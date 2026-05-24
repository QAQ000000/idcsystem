<?php

namespace App\Modules\Order\Services;

use App\Models\HostActionLog;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\Upgrade;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Services\NotificationService;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class HostService
{
    private PricingService $pricingService;

    private InvoiceService $invoiceService;

    public function __construct(?PricingService $pricingService = null, ?InvoiceService $invoiceService = null)
    {
        $this->pricingService = $pricingService ?? new PricingService();
        $this->invoiceService = $invoiceService ?? new InvoiceService();
    }

    /**
     * 为订单创建产品实例。
     */
    public function create(Order $order, Product $product, array $config): Host
    {
        return DB::transaction(function () use ($order, $product, $config) {
            $billingCycle = (string) ($config['billing_cycle'] ?? 'monthly');
            $amount = $this->pricingService->calculatePrice($product, $billingCycle, $config);

            $host = Host::create([
                'client_id' => $order->client_id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'server_id' => (int) ($config['server_id'] ?? 0),
                'domain' => $config['domain'] ?? null,
                'username' => $config['username'] ?? null,
                'password' => isset($config['password']) ? Hash::make((string) $config['password']) : null,
                'billing_cycle' => $billingCycle,
                'first_payment_amount' => $amount,
                'recurring_amount' => $amount,
                'registered_at' => null,
                'next_due_date' => $this->nextDueDate($billingCycle),
                'next_invoice_date' => $this->nextInvoiceDate($billingCycle),
                'status' => 'Pending',
                'auto_renew' => (bool) ($config['auto_renew'] ?? true),
                'notes' => $config['notes'] ?? null,
            ]);

            $this->log($host, 'created', '服务已创建');

            return $host;
        });
    }

    /**
     * 开通服务，存在服务器插件时调用插件。
     */
    public function provision(Host $host): bool
    {
        return DB::transaction(function () use ($host) {
            $plugin = $this->serverPlugin($host);
            if ($plugin) {
                $result = $plugin->createAccount($this->serverParams($host));
                if (($result['success'] ?? false) !== true) {
                    $this->log($host, 'provision_failed', $result['message'] ?? '服务开通失败', [
                        'result' => $result,
                    ]);

                    return false;
                }

                $host->fill([
                    'username' => $result['username'] ?? $host->username,
                    'password' => $result['password'] ?? $host->password,
                    'server_id' => $result['server_id'] ?? $host->server_id,
                ]);
            }

            $saved = $host->fill([
                'status' => 'Active',
                'registered_at' => $host->registered_at ?: now(),
                'suspend_reason' => null,
            ])->save();

            if ($saved) {
                $this->log($host, 'provision', '服务已开通');
            }

            return $saved;
        });
    }

    /**
     * 暂停服务。
     */
    public function suspend(Host $host, string $reason): bool
    {
        return DB::transaction(function () use ($host, $reason) {
            $plugin = $this->serverPlugin($host);
            if ($plugin && !$plugin->suspendAccount($this->serverParams($host) + ['reason' => $reason])) {
                return false;
            }

            $updated = $host->update([
                'status' => 'Suspended',
                'suspend_reason' => $reason,
            ]);

            if ($updated) {
                $this->log($host, 'suspend', $reason);
            }

            return $updated;
        });
    }

    /**
     * 解除暂停。
     */
    public function unsuspend(Host $host): bool
    {
        return DB::transaction(function () use ($host) {
            $plugin = $this->serverPlugin($host);
            if ($plugin && !$plugin->unsuspendAccount($this->serverParams($host))) {
                return false;
            }

            $updated = $host->update([
                'status' => 'Active',
                'suspend_reason' => null,
            ]);

            if ($updated) {
                $this->log($host, 'unsuspend', '服务已解除暂停');
            }

            return $updated;
        });
    }

    /**
     * 终止服务。
     */
    public function terminate(Host $host): bool
    {
        return DB::transaction(function () use ($host) {
            $plugin = $this->serverPlugin($host);
            if ($plugin && !$plugin->terminateAccount($this->serverParams($host))) {
                return false;
            }

            $updated = $host->update([
                'status' => 'Terminated',
                'termination_date' => now(),
            ]);

            if ($updated) {
                $this->log($host, 'terminate', '服务已终止');
            }

            return $updated;
        });
    }

    /**
     * 生成续费账单。
     */
    public function renew(Host $host, string $billingCycle): Invoice
    {
        return DB::transaction(function () use ($host, $billingCycle) {
            $amount = $this->pricingService->calculatePrice($host->product, $billingCycle, [
                'currency_id' => $host->client->currency_id,
            ]);

            $invoice = $this->invoiceService->generate($host->client, [[
                'type' => 'renewal',
                'description' => $host->product->name . ' renewal (' . $billingCycle . ')',
                'amount' => $amount,
                'rel_id' => $host->id,
            ]]);

            $this->log($host, 'renew_invoice', '续费账单已生成', [
                'invoice_id' => $invoice->id,
                'billing_cycle' => $billingCycle,
            ]);

            app(NotificationService::class)->notifyClient($host->client, 'host_renewal_invoice_created', [
                'client_name' => $host->client->username,
                'product_name' => $host->product->name,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->total,
            ]);

            return $invoice;
        });
    }

    public function createUpgradeInvoice(Host $host, Product $targetProduct): Invoice
    {
        return DB::transaction(function () use ($host, $targetProduct) {
            $current = $this->pricingService->calculatePrice($host->product, $host->billing_cycle, [
                'currency_id' => $host->client->currency_id,
            ]);
            $target = $this->pricingService->calculatePrice($targetProduct, $host->billing_cycle, [
                'currency_id' => $host->client->currency_id,
            ]);
            $amount = round(max(0, $target - $current), 2);
            $type = $target >= $current ? 'upgrade' : 'downgrade';

            $upgrade = Upgrade::query()->create([
                'host_id' => $host->id,
                'type' => $type,
                'from_product_id' => $host->product_id,
                'to_product_id' => $targetProduct->id,
                'amount' => $amount,
                'status' => 'Pending',
            ]);

            $invoice = $this->invoiceService->generate($host->client, [[
                'type' => 'upgrade',
                'description' => $host->product->name . ' to ' . $targetProduct->name,
                'amount' => $amount,
                'rel_id' => $upgrade->id,
            ]]);

            $this->log($host, $type . '_invoice', '升级/降配账单已生成', [
                'invoice_id' => $invoice->id,
                'upgrade_id' => $upgrade->id,
            ]);

            return $invoice;
        });
    }

    public function applyPaidInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            if ($item->type === 'renewal') {
                $this->applyRenewalItem($item);
            }

            if ($item->type === 'upgrade') {
                $this->applyUpgradeItem($item);
            }
        }
    }

    public function resetPassword(Host $host): bool
    {
        if (!in_array($host->status, ['Active', 'Suspended'], true)) {
            return false;
        }

        $password = Str::password(12);
        $plugin = $this->serverPlugin($host);
        if ($plugin && !$plugin->changePassword($this->serverParams($host) + ['new_password' => $password])) {
            return false;
        }

        $updated = $host->update(['password' => Hash::make($password)]);
        if ($updated) {
            $this->log($host, 'reset_password', '服务密码已重置');
        }

        return $updated;
    }

    public function reboot(Host $host): bool
    {
        if ($host->status !== 'Active') {
            return false;
        }

        $this->log($host, 'reboot', '服务重启请求已记录');

        return true;
    }

    public function cancelAutoRenew(Host $host): bool
    {
        $updated = $host->update(['auto_renew' => false]);
        if ($updated) {
            $this->log($host, 'cancel_auto_renew', '自动续费已取消');
        }

        return $updated;
    }

    public function availableCycles(): array
    {
        return ['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'];
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

    private function serverParams(Host $host): array
    {
        return [
            'host_id' => $host->id,
            'client_id' => $host->client_id,
            'product_id' => $host->product_id,
            'domain' => $host->domain,
            'username' => $host->username,
            'password' => $host->password,
            'billing_cycle' => $host->billing_cycle,
            'server_id' => $host->server_id,
            'config' => is_array($host->notes) ? $host->notes : [],
        ];
    }

    private function nextDueDate(string $billingCycle): ?\Carbon\Carbon
    {
        return match (strtolower($billingCycle)) {
            'hourly' => now()->addHour(),
            'daily' => now()->addDay(),
            'monthly' => now()->addMonthNoOverflow(),
            'quarterly' => now()->addMonthsNoOverflow(3),
            'semiannually' => now()->addMonthsNoOverflow(6),
            'annually' => now()->addYearNoOverflow(),
            'biennially' => now()->addYearsNoOverflow(2),
            'triennially' => now()->addYearsNoOverflow(3),
            'onetime' => null,
            default => now()->addMonthNoOverflow(),
        };
    }

    private function nextInvoiceDate(string $billingCycle): ?\Carbon\Carbon
    {
        $dueDate = $this->nextDueDate($billingCycle);

        return $dueDate?->copy()->subDays(7);
    }

    private function applyRenewalItem(InvoiceItem $item): void
    {
        $host = Host::query()->with(['client', 'product'])->find((int) $item->rel_id);
        if (!$host) {
            return;
        }

        $base = $host->next_due_date && $host->next_due_date->isFuture() ? $host->next_due_date : now();
        $nextDueDate = $this->addCycle($base, $host->billing_cycle);
        $host->update([
            'next_due_date' => $nextDueDate,
            'next_invoice_date' => $nextDueDate?->copy()->subDays(7),
            'status' => $host->status === 'Suspended' ? 'Active' : $host->status,
        ]);
        $this->log($host, 'renew_paid', '续费已生效', ['invoice_item_id' => $item->id]);
    }

    private function applyUpgradeItem(InvoiceItem $item): void
    {
        $upgrade = Upgrade::query()->with('host.product')->find((int) $item->rel_id);
        if (!$upgrade || $upgrade->status === 'Completed') {
            return;
        }

        $host = $upgrade->host;
        $host->update([
            'product_id' => $upgrade->to_product_id,
            'recurring_amount' => $upgrade->amount > 0 ? $upgrade->amount : $host->recurring_amount,
        ]);
        $upgrade->update([
            'status' => 'Completed',
            'completed_at' => now(),
        ]);
        $this->log($host, $upgrade->type . '_completed', '升级/降配已生效', ['upgrade_id' => $upgrade->id]);
        $host->refresh()->loadMissing(['client', 'product']);
        app(NotificationService::class)->notifyClient($host->client, 'host_upgrade_completed', [
            'client_name' => $host->client->username,
            'product_name' => $host->product?->name ?? '服务',
        ]);
    }

    private function addCycle(\Carbon\Carbon $date, string $billingCycle): ?\Carbon\Carbon
    {
        return match (strtolower($billingCycle)) {
            'hourly' => $date->copy()->addHour(),
            'daily' => $date->copy()->addDay(),
            'monthly' => $date->copy()->addMonthNoOverflow(),
            'quarterly' => $date->copy()->addMonthsNoOverflow(3),
            'semiannually' => $date->copy()->addMonthsNoOverflow(6),
            'annually' => $date->copy()->addYearNoOverflow(),
            'biennially' => $date->copy()->addYearsNoOverflow(2),
            'triennially' => $date->copy()->addYearsNoOverflow(3),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }

    private function log(Host $host, string $action, ?string $message = null, array $meta = []): void
    {
        HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => $action,
            'message' => $message,
            'meta' => $meta,
        ]);
    }
}
