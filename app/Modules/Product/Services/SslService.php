<?php

namespace App\Modules\Product\Services;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Product\Models\SslCertificate;
use App\Modules\User\Models\Client;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SslService
{
    public function __construct(private ?InvoiceService $invoices = null)
    {
        $this->invoices ??= app(InvoiceService::class);
    }

    public function purchase(Client $client, string $domain, string $type, int $years, ?Host $host = null): SslCertificate
    {
        $domain = $this->normalizeDomain($domain);
        $type = $this->normalizeType($type);
        $years = $this->normalizeYears($years);

        if ($host && (int) $host->client_id !== (int) $client->id) {
            throw new InvalidArgumentException('关联主机不属于当前客户。');
        }

        return DB::transaction(function () use ($client, $domain, $type, $years, $host) {
            $certificate = SslCertificate::query()->create([
                'client_id' => $client->id,
                'host_id' => $host?->id,
                'domain' => $domain,
                'type' => $type,
                'status' => 'Pending',
                'auto_renew' => $type === 'letsencrypt',
            ]);

            if ($type === 'letsencrypt') {
                $this->issueLetsEncrypt($certificate);

                return $certificate->fresh();
            }

            $this->invoices->generate($client, [[
                'type' => 'ssl_purchase',
                'description' => sprintf('SSL certificate purchase %s (%d year%s)', $domain, $years, $years > 1 ? 's' : ''),
                'amount' => round($this->paidCertificatePrice() * $years, 2),
                'rel_id' => $certificate->id,
                'meta' => ['years' => $years],
            ]]);

            return $certificate->fresh();
        });
    }

    public function issueLetsEncrypt(SslCertificate $certificate): bool
    {
        if ($certificate->type !== 'letsencrypt') {
            throw new RuntimeException('仅 Let’s Encrypt 证书支持自动签发。');
        }

        return $certificate->update([
            'status' => 'Active',
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(90)->toDateString(),
            'certificate' => $this->fakePem('CERTIFICATE', $certificate->domain),
            'private_key' => $this->fakePem('PRIVATE KEY', $certificate->domain),
            'ca_bundle' => $this->fakePem('CERTIFICATE', 'lets-encrypt-ca'),
            'auto_renew' => true,
        ]);
    }

    public function renew(SslCertificate $certificate): Invoice
    {
        $certificate->loadMissing('client');
        if ($certificate->type === 'letsencrypt') {
            throw new RuntimeException('Let’s Encrypt 证书请使用自动续签。');
        }

        if ($this->hasUnpaidInvoice($certificate, 'ssl_renew')) {
            throw new RuntimeException('该证书已有未支付的续费账单。');
        }

        return $this->invoices->generate($certificate->client, [[
            'type' => 'ssl_renew',
            'description' => 'SSL certificate renewal ' . $certificate->domain,
            'amount' => $this->paidCertificatePrice(),
            'rel_id' => $certificate->id,
            'meta' => ['years' => 1],
        ]]);
    }

    public function autoRenewLetsEncrypt(SslCertificate $certificate): bool
    {
        if ($certificate->type !== 'letsencrypt' || !$certificate->auto_renew) {
            return false;
        }

        if ($certificate->expiry_date && $certificate->expiry_date->greaterThan(now()->addDays(30))) {
            return false;
        }

        return $this->issueLetsEncrypt($certificate);
    }

    public function deploy(SslCertificate $certificate): bool
    {
        $certificate->loadMissing('host.product');
        if (!$certificate->host || $certificate->status !== 'Active') {
            return false;
        }

        $plugin = $this->serverPlugin($certificate->host);
        if (!$plugin || !method_exists($plugin, 'installSslCertificate')) {
            return false;
        }

        return (bool) $plugin->installSslCertificate([
            'host_id' => $certificate->host->id,
            'client_id' => $certificate->client_id,
            'domain' => $certificate->domain,
            'certificate' => $certificate->certificate,
            'private_key' => $certificate->private_key,
            'ca_bundle' => $certificate->ca_bundle,
        ]);
    }

    public function sendExpiryReminders(array $days = [30, 15, 7]): int
    {
        $sent = 0;
        foreach ($days as $day) {
            $certificates = SslCertificate::query()
                ->with('client')
                ->where('type', 'paid')
                ->where('status', 'Active')
                ->whereDate('expiry_date', now()->addDays((int) $day)->toDateString())
                ->get();

            foreach ($certificates as $certificate) {
                app(NotificationService::class)->notifyClient($certificate->client, 'ssl_expiry_reminder', [
                    'client_name' => $certificate->client->username,
                    'domain' => $certificate->domain,
                    'expiry_date' => $certificate->expiry_date?->format('Y-m-d'),
                    'days' => (int) $day,
                ]);
                $sent++;
            }
        }

        return $sent;
    }

    public function autoRenewLetsEncryptDue(): int
    {
        $count = 0;
        SslCertificate::query()
            ->where('type', 'letsencrypt')
            ->where('auto_renew', true)
            ->whereIn('status', ['Active', 'Pending', 'Expired'])
            ->where(function ($query): void {
                $query->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '<=', now()->addDays(30)->toDateString());
            })
            ->chunkById(100, function ($certificates) use (&$count): void {
                foreach ($certificates as $certificate) {
                    if ($this->autoRenewLetsEncrypt($certificate)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function applyPaidInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items');
        foreach ($invoice->items as $item) {
            if (in_array($item->type, ['ssl_purchase', 'ssl_renew'], true)) {
                $this->applyPaidItem($item);
            }
        }
    }

    private function applyPaidItem(InvoiceItem $item): void
    {
        $meta = is_array($item->meta) ? $item->meta : [];
        if (!empty($meta['ssl_applied_at'])) {
            return;
        }

        $certificate = SslCertificate::query()->find((int) $item->rel_id);
        if (!$certificate) {
            return;
        }

        $years = $this->normalizeYears((int) ($meta['years'] ?? 1));
        $base = $certificate->expiry_date && ($certificate->expiry_date->isToday() || $certificate->expiry_date->isFuture())
            ? $certificate->expiry_date
            : now();

        $certificate->update([
            'status' => 'Active',
            'issue_date' => $certificate->issue_date ?: now()->toDateString(),
            'expiry_date' => $base->copy()->addYearsNoOverflow($years)->toDateString(),
            'certificate' => $certificate->certificate ?: $this->fakePem('CERTIFICATE', $certificate->domain),
            'private_key' => $certificate->private_key ?: $this->fakePem('PRIVATE KEY', $certificate->domain),
            'ca_bundle' => $certificate->ca_bundle ?: $this->fakePem('CERTIFICATE', 'paid-ca'),
        ]);
        $item->update(['meta' => $meta + ['ssl_applied_at' => now()->toIso8601String()]]);
    }

    private function hasUnpaidInvoice(SslCertificate $certificate, string $type): bool
    {
        return InvoiceItem::query()
            ->where('type', $type)
            ->where('rel_id', $certificate->id)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'Unpaid'))
            ->exists();
    }

    private function serverPlugin(Host $host): ?ServerModuleInterface
    {
        $serverType = $host->product?->server_type;
        if (!$serverType) {
            return null;
        }

        $plugin = Plugin::get($serverType);

        return $plugin instanceof ServerModuleInterface ? $plugin : null;
    }

    private function paidCertificatePrice(): float
    {
        return round((float) config('billing.ssl_certificate_price', 99), 2);
    }

    private function normalizeYears(int $years): int
    {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException('年限必须在 1 到 10 年之间。');
        }

        return $years;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['paid', 'letsencrypt'], true)) {
            throw new InvalidArgumentException('证书类型不正确。');
        }

        return $type;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (!preg_match('/^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,20}$/', $domain)) {
            throw new InvalidArgumentException('域名格式不正确。');
        }

        return $domain;
    }

    private function fakePem(string $label, string $domain): string
    {
        $body = chunk_split(base64_encode($domain . '|' . now()->toIso8601String()), 64, "\n");

        return "-----BEGIN {$label}-----\n{$body}-----END {$label}-----";
    }
}
