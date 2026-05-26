<?php

namespace App\Modules\Product\Services;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Product\Models\Domain;
use App\Modules\Product\Models\DomainPricing;
use App\Modules\User\Models\Client;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DomainService
{
    public function __construct(private ?InvoiceService $invoices = null)
    {
        $this->invoices ??= app(InvoiceService::class);
    }

    public function register(Client $client, string $domain, int $years, bool $whoisPrivacy): Domain
    {
        $domainName = $this->normalizeDomain($domain);
        $years = $this->normalizeYears($years);
        if (!$this->checkAvailability($domainName)) {
            throw new RuntimeException('域名不可注册。');
        }

        $pricing = $this->pricingFor($this->extractTld($domainName));
        if (!$pricing) {
            throw new RuntimeException('该后缀暂未开放注册。');
        }

        return DB::transaction(function () use ($client, $domainName, $years, $whoisPrivacy, $pricing) {
            $domain = Domain::query()->create([
                'client_id' => $client->id,
                'domain' => $domainName,
                'tld' => $pricing->tld,
                'status' => 'Pending',
                'registration_date' => now()->toDateString(),
                'expiry_date' => now()->addYears($years)->toDateString(),
                'auto_renew' => true,
                'whois_privacy' => $whoisPrivacy,
                'nameservers' => [],
                'registrar' => 'manual',
            ]);

            $this->invoices->generate($client, [[
                'type' => 'domain_register',
                'description' => sprintf('Domain registration %s (%d year%s)', $domainName, $years, $years > 1 ? 's' : ''),
                'amount' => round((float) $pricing->register_price * $years, 2),
                'rel_id' => $domain->id,
            ]]);

            return $domain->fresh();
        });
    }

    public function renew(Domain $domain, int $years): Invoice
    {
        $domain->loadMissing('client');
        $years = $this->normalizeYears($years);
        if ($this->hasUnpaidInvoice($domain, 'domain_renew')) {
            throw new RuntimeException('该域名已有未支付的续费账单。');
        }

        $pricing = $this->pricingFor($domain->tld);
        if (!$pricing) {
            throw new RuntimeException('该后缀暂未开放续费。');
        }

        return $this->invoices->generate($domain->client, [[
            'type' => 'domain_renew',
            'description' => sprintf('Domain renewal %s (%d year%s)', $domain->domain, $years, $years > 1 ? 's' : ''),
            'amount' => round((float) $pricing->renew_price * $years, 2),
            'rel_id' => $domain->id,
            'meta' => ['years' => $years],
        ]]);
    }

    public function transfer(Client $client, string $domain, string $authCode): Domain
    {
        $domainName = $this->normalizeDomain($domain);
        $authCode = trim($authCode);
        if ($authCode === '') {
            throw new InvalidArgumentException('转入授权码不能为空。');
        }

        $pricing = $this->pricingFor($this->extractTld($domainName));
        if (!$pricing) {
            throw new RuntimeException('该后缀暂未开放转入。');
        }

        return DB::transaction(function () use ($client, $domainName, $authCode, $pricing) {
            $domain = Domain::query()->create([
                'client_id' => $client->id,
                'domain' => $domainName,
                'tld' => $pricing->tld,
                'status' => 'Transferred',
                'registration_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'auto_renew' => true,
                'whois_privacy' => false,
                'nameservers' => [],
                'registrar' => 'manual',
            ]);

            $this->invoices->generate($client, [[
                'type' => 'domain_transfer',
                'description' => 'Domain transfer ' . $domainName,
                'amount' => (float) $pricing->transfer_price,
                'rel_id' => $domain->id,
                'meta' => ['auth_code_hash' => sha1($authCode)],
            ]]);

            return $domain->fresh();
        });
    }

    public function updateNameservers(Domain $domain, array $nameservers): bool
    {
        $nameservers = collect($nameservers)
            ->map(fn ($value): string => strtolower(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if (count($nameservers) < 2 || count($nameservers) > 6) {
            throw new InvalidArgumentException('DNS 服务器数量必须为 2 到 6 个。');
        }

        foreach ($nameservers as $nameserver) {
            if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $nameserver)) {
                throw new InvalidArgumentException('DNS 服务器格式不正确。');
            }
        }

        return $domain->update(['nameservers' => $nameservers]);
    }

    public function toggleWhoisPrivacy(Domain $domain, bool $enabled): bool
    {
        return $domain->update(['whois_privacy' => $enabled]);
    }

    public function checkAvailability(string $domain): bool
    {
        $domainName = $this->normalizeDomain($domain);

        return !Domain::query()
            ->where('domain', $domainName)
            ->whereNotIn('status', ['Cancelled', 'Expired'])
            ->exists();
    }

    public function sendExpiryReminders(array $days = [30, 15, 7, 3, 1]): int
    {
        $sent = 0;
        foreach ($days as $day) {
            $domains = Domain::query()
                ->with('client')
                ->where('status', 'Active')
                ->whereDate('expiry_date', now()->addDays((int) $day)->toDateString())
                ->get();

            foreach ($domains as $domain) {
                app(NotificationService::class)->notifyClient($domain->client, 'domain_expiry_reminder', [
                    'client_name' => $domain->client->username,
                    'domain' => $domain->domain,
                    'expiry_date' => $domain->expiry_date?->format('Y-m-d'),
                    'days' => (int) $day,
                ]);
                $sent++;
            }
        }

        return $sent;
    }

    public function autoRenewDue(): int
    {
        $count = 0;
        Domain::query()
            ->with('client')
            ->where('status', 'Active')
            ->where('auto_renew', true)
            ->whereDate('expiry_date', '<=', now()->addDays(7)->toDateString())
            ->chunkById(100, function ($domains) use (&$count): void {
                foreach ($domains as $domain) {
                    if ($this->hasUnpaidInvoice($domain, 'domain_renew')) {
                        continue;
                    }

                    $this->renew($domain, 1);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * 支付成功后让域名业务状态落地：注册/转入激活，续费延长到期日。
     */
    public function applyPaidInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            if (in_array($item->type, ['domain_register', 'domain_transfer'], true)) {
                $this->activateDomainItem($item);
            }

            if ($item->type === 'domain_renew') {
                $this->applyRenewalItem($item);
            }
        }
    }

    private function activateDomainItem(InvoiceItem $item): void
    {
        $meta = is_array($item->meta) ? $item->meta : [];
        if (!empty($meta['domain_applied_at'])) {
            return;
        }

        $domain = Domain::query()->find((int) $item->rel_id);
        if (!$domain) {
            return;
        }

        $domain->update(['status' => 'Active']);
        $item->update(['meta' => $meta + ['domain_applied_at' => now()->toIso8601String()]]);
    }

    private function applyRenewalItem(InvoiceItem $item): void
    {
        $meta = is_array($item->meta) ? $item->meta : [];
        if (!empty($meta['domain_applied_at'])) {
            return;
        }

        $domain = Domain::query()->find((int) $item->rel_id);
        if (!$domain) {
            return;
        }

        $years = $this->normalizeYears((int) ($meta['years'] ?? 1));
        $base = $domain->expiry_date && ($domain->expiry_date->isToday() || $domain->expiry_date->isFuture())
            ? $domain->expiry_date
            : now();

        $domain->update([
            'status' => 'Active',
            'expiry_date' => $base->copy()->addYearsNoOverflow($years)->toDateString(),
        ]);
        $item->update(['meta' => $meta + ['domain_applied_at' => now()->toIso8601String()]]);
    }

    private function hasUnpaidInvoice(Domain $domain, string $type): bool
    {
        return InvoiceItem::query()
            ->where('type', $type)
            ->where('rel_id', $domain->id)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'Unpaid'))
            ->exists();
    }

    private function pricingFor(string $tld): ?DomainPricing
    {
        return DomainPricing::query()
            ->where('tld', $this->normalizeTld($tld))
            ->where('active', true)
            ->first();
    }

    private function normalizeYears(int $years): int
    {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException('年限必须在 1 到 10 年之间。');
        }

        return $years;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (!preg_match('/^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,20}$/', $domain)) {
            throw new InvalidArgumentException('域名格式不正确。');
        }

        return $domain;
    }

    private function extractTld(string $domain): string
    {
        $parts = explode('.', $domain);

        return $this->normalizeTld((string) end($parts));
    }

    private function normalizeTld(string $tld): string
    {
        return '.' . ltrim(strtolower(trim($tld)), '.');
    }
}
