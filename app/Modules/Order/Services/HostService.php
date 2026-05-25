<?php

namespace App\Modules\Order\Services;

use App\Models\HostActionLog;
use App\Modules\Finance\Models\Credit;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\Upgrade;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\ProductService;
use App\Modules\Product\Services\PricingService;
use App\Modules\User\Services\ClientService;
use App\Services\Concerns\NotifiesClientsSafely;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class HostService
{
    use NotifiesClientsSafely;

    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|credential|authorization|cookie|session_id|session|bearer|access_key|private_key|key|signature|sign)$/i';

    private PricingService $pricingService;

    private InvoiceService $invoiceService;

    private ProductService $productService;

    public function __construct(?PricingService $pricingService = null, ?InvoiceService $invoiceService = null, ?ProductService $productService = null)
    {
        $this->pricingService = $pricingService ?? new PricingService();
        $this->invoiceService = $invoiceService ?? new InvoiceService();
        $this->productService = $productService ?? new ProductService();
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
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return false;
            }

            if (!$this->canClientReceiveServiceOperation($lockedHost)) {
                $this->logFailure($lockedHost, 'provision', '客户账号状态不允许开通服务', $this->clientStateMeta($lockedHost));

                return false;
            }

            if ($lockedHost->status !== 'Pending') {
                $this->logFailure($lockedHost, 'provision', '当前服务状态不允许开通', ['status' => $lockedHost->status]);

                return false;
            }

            if (!$this->isProvisionPaid($lockedHost)) {
                $this->logFailure($lockedHost, 'provision', '关联订单或账单未支付，不能开通服务', [
                    'order_id' => $lockedHost->order_id,
                    'order_status' => $lockedHost->order?->status,
                    'invoice_id' => $lockedHost->order?->invoice_id,
                    'invoice_status' => $lockedHost->order?->invoice?->status,
                ]);

                return false;
            }

            try {
                $plugin = $this->serverPlugin($lockedHost);
            } catch (RuntimeException $exception) {
                $this->logFailure($lockedHost, 'provision', $exception->getMessage());

                return false;
            }

            if ($plugin) {
                $result = $plugin->createAccount($this->serverParams($lockedHost));
                if (($result['success'] ?? false) !== true) {
                    $this->log($lockedHost, 'provision_failed', $result['message'] ?? '服务开通失败', [
                        'result' => $result,
                    ]);

                    return false;
                }

                $lockedHost->fill([
                    'username' => $result['username'] ?? $lockedHost->username,
                    'password' => array_key_exists('password', $result)
                        ? $this->storedPassword((string) $result['password'])
                        : $lockedHost->password,
                    'server_id' => $result['server_id'] ?? $lockedHost->server_id,
                ]);
            }

            $saved = $lockedHost->fill([
                'status' => 'Active',
                'registered_at' => $lockedHost->registered_at ?: now(),
                'suspend_reason' => null,
            ])->save();

            if ($saved) {
                $this->log($lockedHost, 'provision', '服务已开通');
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
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return false;
            }

            if ($lockedHost->status !== 'Active') {
                $this->logFailure($lockedHost, 'suspend', '当前服务状态不允许暂停', ['status' => $lockedHost->status]);

                return false;
            }

            try {
                $plugin = $this->serverPlugin($lockedHost);
            } catch (RuntimeException $exception) {
                $this->logFailure($lockedHost, 'suspend', $exception->getMessage());

                return false;
            }

            if ($plugin && !$plugin->suspendAccount($this->serverParams($lockedHost) + ['reason' => $reason])) {
                $this->logFailure($lockedHost, 'suspend', '服务器模块暂停失败');

                return false;
            }

            $updated = $lockedHost->update([
                'status' => 'Suspended',
                'suspend_reason' => $reason,
            ]);

            if ($updated) {
                $this->log($lockedHost, 'suspend', $reason);
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
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return false;
            }

            if (!$this->canClientReceiveServiceOperation($lockedHost)) {
                $this->logFailure($lockedHost, 'unsuspend', '客户账号状态不允许解除暂停', $this->clientStateMeta($lockedHost));

                return false;
            }

            if ($lockedHost->status !== 'Suspended') {
                $this->logFailure($lockedHost, 'unsuspend', '当前服务状态不允许解除暂停', ['status' => $lockedHost->status]);

                return false;
            }

            try {
                $plugin = $this->serverPlugin($lockedHost);
            } catch (RuntimeException $exception) {
                $this->logFailure($lockedHost, 'unsuspend', $exception->getMessage());

                return false;
            }

            if ($plugin && !$plugin->unsuspendAccount($this->serverParams($lockedHost))) {
                $this->logFailure($lockedHost, 'unsuspend', '服务器模块解除暂停失败');

                return false;
            }

            $updated = $lockedHost->update([
                'status' => 'Active',
                'suspend_reason' => null,
            ]);

            if ($updated) {
                $this->log($lockedHost, 'unsuspend', '服务已解除暂停');
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
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return false;
            }

            if (!in_array($lockedHost->status, ['Active', 'Suspended'], true)) {
                $this->logFailure($lockedHost, 'terminate', '当前服务状态不允许终止', ['status' => $lockedHost->status]);

                return false;
            }

            try {
                $plugin = $this->serverPlugin($lockedHost);
            } catch (RuntimeException $exception) {
                $this->logFailure($lockedHost, 'terminate', $exception->getMessage());

                return false;
            }

            if ($plugin && !$plugin->terminateAccount($this->serverParams($lockedHost))) {
                $this->logFailure($lockedHost, 'terminate', '服务器模块终止失败');

                return false;
            }

            $updated = $lockedHost->update([
                'status' => 'Terminated',
                'termination_date' => now(),
            ]);

            if ($updated) {
                $this->log($lockedHost, 'terminate', '服务已终止');
            }

            return $updated;
        });
    }

    /**
     * 生成续费账单。
     */
    public function renew(Host $host, string $billingCycle): Invoice
    {
        $result = DB::transaction(function () use ($host, $billingCycle) {
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return ['error' => '服务不存在。'];
            }

            if (!$this->canClientCreateBusiness($lockedHost)) {
                $this->logFailure($lockedHost, 'renew_invoice', '客户账号状态不允许续费', [
                    'client_id' => $lockedHost->client_id,
                    'client_status' => $lockedHost->client?->status,
                    'client_deleted' => (bool) $lockedHost->client?->trashed(),
                ]);

                return ['error' => '客户账号状态不允许续费。'];
            }

            if (in_array($lockedHost->status, ['Terminated', 'Cancelled'], true)) {
                $this->logFailure($lockedHost, 'renew_invoice', '当前服务状态不允许续费', [
                    'status' => $lockedHost->status,
                    'billing_cycle' => $billingCycle,
                ]);

                return ['error' => '当前服务状态不允许续费。'];
            }

            if ($this->hasUnpaidRenewalInvoice($lockedHost)) {
                $this->logFailure($lockedHost, 'renew_invoice', '已有未支付的续费账单', [
                    'host_id' => $lockedHost->id,
                    'billing_cycle' => $billingCycle,
                ]);

                return ['error' => '已有未支付的续费账单，请先完成支付或取消后再提交。'];
            }

            $amount = $this->pricingService->calculatePrice($lockedHost->product, $billingCycle, [
                'currency_id' => $lockedHost->client->currency_id,
            ]);
            if ($amount <= 0) {
                $this->logFailure($lockedHost, 'renew_invoice', '续费周期未配置有效价格', [
                    'billing_cycle' => $billingCycle,
                ]);

                return ['error' => '当前续费周期未配置有效价格。'];
            }

            $invoice = $this->invoiceService->generate($lockedHost->client, [[
                'type' => 'renewal',
                'description' => $lockedHost->product->name . ' renewal (' . $billingCycle . ')',
                'amount' => $amount,
                'rel_id' => $lockedHost->id,
                'meta' => [
                    'billing_cycle' => $billingCycle,
                ],
            ]]);

            $this->log($lockedHost, 'renew_invoice', '续费账单已生成', [
                'invoice_id' => $invoice->id,
                'billing_cycle' => $billingCycle,
            ]);

            $this->notifyClientSafely($lockedHost->client, 'host_renewal_invoice_created', [
                'client_name' => $lockedHost->client->username,
                'product_name' => $lockedHost->product->name,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->total,
            ], 'host.renew_invoice');

            return ['invoice' => $invoice];
        });

        if (isset($result['error'])) {
            throw new RuntimeException($result['error']);
        }

        return $result['invoice'];
    }

    public function createUpgradeInvoice(Host $host, Product $targetProduct): Invoice
    {
        $result = DB::transaction(function () use ($host, $targetProduct) {
            $targetProduct = Product::query()->whereKey($targetProduct->id)->lockForUpdate()->first();
            if (!$targetProduct) {
                return ['error' => '升级/降配目标产品不可购买。'];
            }

            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return ['error' => '服务不存在。'];
            }

            if (!$this->canClientCreateBusiness($lockedHost)) {
                $this->logFailure($lockedHost, 'upgrade_invoice', '客户账号状态不允许升级/降配', [
                    'client_id' => $lockedHost->client_id,
                    'client_status' => $lockedHost->client?->status,
                    'client_deleted' => (bool) $lockedHost->client?->trashed(),
                ]);

                return ['error' => '客户账号状态不允许升级/降配。'];
            }

            if ($lockedHost->status !== 'Active') {
                $this->logFailure($lockedHost, 'upgrade_invoice', '当前服务状态不允许升级/降配', [
                    'status' => $lockedHost->status,
                ]);

                return ['error' => '当前服务状态不允许升级/降配。'];
            }

            if (!$this->productService->isPurchasable($targetProduct)) {
                $this->logFailure($lockedHost, 'upgrade_invoice', '升级/降配目标产品不可购买', [
                    'to_product_id' => $targetProduct->id,
                    'hidden' => (bool) $targetProduct->hidden,
                    'retired' => (bool) $targetProduct->retired,
                    'stock_control' => (bool) $targetProduct->stock_control,
                    'stock_qty' => (int) $targetProduct->stock_qty,
                ]);

                return ['error' => '升级/降配目标产品不可购买。'];
            }

            if ((int) $targetProduct->id === (int) $lockedHost->product_id) {
                $this->logFailure($lockedHost, 'upgrade_invoice', '目标产品不能与当前产品相同', [
                    'product_id' => $lockedHost->product_id,
                ]);

                return ['error' => '目标产品不能与当前产品相同。'];
            }

            if (!$this->isCompatibleUpgradeTarget($lockedHost->product, $targetProduct)) {
                $this->logFailure($lockedHost, 'upgrade_invoice', '升级/降配目标产品与当前服务不兼容', [
                    'from_product_id' => $lockedHost->product_id,
                    'to_product_id' => $targetProduct->id,
                    'from_type' => $lockedHost->product?->type,
                    'to_type' => $targetProduct->type,
                    'from_server_type' => $lockedHost->product?->server_type,
                    'to_server_type' => $targetProduct->server_type,
                ]);

                return ['error' => '升级/降配目标产品与当前服务不兼容。'];
            }

            if (Upgrade::query()->where('host_id', $lockedHost->id)->where('status', 'Pending')->exists()) {
                $this->logFailure($lockedHost, 'upgrade_invoice', '已有待处理的升级/降配账单', [
                    'host_id' => $lockedHost->id,
                    'to_product_id' => $targetProduct->id,
                ]);

                return ['error' => '已有待处理的升级/降配账单，请先完成或取消后再提交。'];
            }

            $current = $this->pricingService->calculatePrice($lockedHost->product, $lockedHost->billing_cycle, [
                'currency_id' => $lockedHost->client->currency_id,
            ]);
            $target = $this->pricingService->calculatePrice($targetProduct, $lockedHost->billing_cycle, [
                'currency_id' => $lockedHost->client->currency_id,
            ]);
            if ($current <= 0 || $target <= 0) {
                $this->logFailure($lockedHost, 'upgrade_invoice', '升级/降配目标未配置有效价格', [
                    'from_product_id' => $lockedHost->product_id,
                    'to_product_id' => $targetProduct->id,
                    'billing_cycle' => $lockedHost->billing_cycle,
                    'currency_id' => $lockedHost->client->currency_id,
                    'current_amount' => $current,
                    'target_amount' => $target,
                ]);

                return ['error' => '升级/降配目标未配置有效价格。'];
            }

            $amount = round(max(0, $target - $current), 2);
            $type = $target >= $current ? 'upgrade' : 'downgrade';

            $upgrade = Upgrade::query()->create([
                'host_id' => $lockedHost->id,
                'type' => $type,
                'from_product_id' => $lockedHost->product_id,
                'to_product_id' => $targetProduct->id,
                'amount' => $amount,
                'status' => 'Pending',
            ]);

            if ($type === 'downgrade') {
                $this->completeUpgrade($upgrade);
                $this->log($lockedHost, 'downgrade_completed', '降配已直接生效', [
                    'upgrade_id' => $upgrade->id,
                    'from_product_id' => $lockedHost->product_id,
                    'to_product_id' => $targetProduct->id,
                    'recurring_amount' => $target,
                ]);

                $invoice = $this->invoiceService->generateNoPaymentRequired($lockedHost->client, [[
                    'type' => 'downgrade',
                    'description' => $lockedHost->product->name . ' to ' . $targetProduct->name . ' downgrade adjustment',
                    'amount' => 0,
                    'rel_id' => $upgrade->id,
                ]]);
                $upgrade->update(['status' => 'Completed', 'completed_at' => now()]);

                return ['invoice' => $invoice->fresh(['items'])];
            }

            $invoice = $this->invoiceService->generate($lockedHost->client, [[
                'type' => 'upgrade',
                'description' => $lockedHost->product->name . ' to ' . $targetProduct->name,
                'amount' => $amount,
                'rel_id' => $upgrade->id,
            ]]);

            $this->log($lockedHost, $type . '_invoice', '升级/降配账单已生成', [
                'invoice_id' => $invoice->id,
                'upgrade_id' => $upgrade->id,
            ]);

            return ['invoice' => $invoice];
        });

        if (isset($result['error'])) {
            throw new RuntimeException($result['error']);
        }

        return $result['invoice'];
    }

    public function applyPaidInvoice(Invoice $invoice): void
    {
        $invoice = Invoice::query()->whereKey($invoice->id)->with('items')->first();
        if (!$invoice || $invoice->status !== 'Paid') {
            return;
        }

        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            if ($item->type === 'renewal') {
                $this->applyRenewalItem($item);
            }

            if ($item->type === 'upgrade') {
                $this->applyUpgradeItem($item);
            }

            if ($item->type === 'recharge') {
                $this->applyRechargeItem($invoice, $item);
            }
        }
    }

    public function resetPassword(Host $host): bool
    {
        return DB::transaction(function () use ($host) {
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return false;
            }

            if (!$this->canClientReceiveServiceOperation($lockedHost)) {
                $this->logFailure($lockedHost, 'reset_password', '客户账号状态不允许重置密码', $this->clientStateMeta($lockedHost));

                return false;
            }

            if (!in_array($lockedHost->status, ['Active', 'Suspended'], true)) {
                $this->logFailure($lockedHost, 'reset_password', '当前服务状态不允许重置密码', ['status' => $lockedHost->status]);

                return false;
            }

            $password = Str::password(12);
            try {
                $plugin = $this->serverPlugin($lockedHost);
            } catch (RuntimeException $exception) {
                $this->logFailure($lockedHost, 'reset_password', $exception->getMessage());

                return false;
            }

            if ($plugin && !$plugin->changePassword($this->serverParams($lockedHost) + ['new_password' => $password])) {
                $this->logFailure($lockedHost, 'reset_password', '服务器模块重置密码失败');

                return false;
            }

            $updated = $lockedHost->update(['password' => Hash::make($password)]);
            if ($updated) {
                $this->log($lockedHost, 'reset_password', '服务密码已重置');
            }

            return $updated;
        });
    }

    public function reboot(Host $host): bool
    {
        return DB::transaction(function () use ($host) {
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return false;
            }

            if (!$this->canClientReceiveServiceOperation($lockedHost)) {
                $this->logFailure($lockedHost, 'reboot', '客户账号状态不允许重启服务', $this->clientStateMeta($lockedHost));

                return false;
            }

            if ($lockedHost->status !== 'Active') {
                $this->logFailure($lockedHost, 'reboot', '当前服务状态不允许重启', ['status' => $lockedHost->status]);

                return false;
            }

            $this->log($lockedHost, 'reboot', '服务重启请求已记录');

            return true;
        });
    }

    public function cancelAutoRenew(Host $host): bool
    {
        return DB::transaction(function () use ($host) {
            $lockedHost = $this->lockHostForOperation($host);
            if (!$lockedHost) {
                return false;
            }

            if (!$this->canClientCreateBusiness($lockedHost)) {
                $this->logFailure($lockedHost, 'cancel_auto_renew', '客户账号状态不允许取消自动续费', $this->clientStateMeta($lockedHost));

                return false;
            }

            if (!$lockedHost->auto_renew) {
                $this->logFailure($lockedHost, 'cancel_auto_renew', '自动续费已经关闭');

                return false;
            }

            $updated = $lockedHost->update(['auto_renew' => false]);
            if ($updated) {
                $this->log($lockedHost, 'cancel_auto_renew', '自动续费已取消');
            } else {
                $this->logFailure($lockedHost, 'cancel_auto_renew', '自动续费关闭失败');
            }

            return $updated;
        });
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

        if (!$plugin instanceof ServerModuleInterface) {
            throw new RuntimeException('服务器模块不可用：' . $serverType);
        }

        return $plugin;
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

    private function storedPassword(string $password): string
    {
        return Hash::needsRehash($password) ? Hash::make($password) : $password;
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

    private function hasUnpaidRenewalInvoice(Host $host): bool
    {
        return InvoiceItem::query()
            ->where('type', 'renewal')
            ->where('rel_id', $host->id)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'Unpaid'))
            ->exists();
    }

    private function isCompatibleUpgradeTarget(?Product $currentProduct, Product $targetProduct): bool
    {
        if (!$currentProduct) {
            return false;
        }

        return $currentProduct->type === $targetProduct->type
            && (string) $currentProduct->server_type === (string) $targetProduct->server_type;
    }

    private function nextInvoiceDate(string $billingCycle): ?\Carbon\Carbon
    {
        $dueDate = $this->nextDueDate($billingCycle);

        return $dueDate?->copy()->subDays(7);
    }

    private function applyRenewalItem(InvoiceItem $item): void
    {
        if ($this->invoiceItemAlreadyApplied($item, 'renew_paid')) {
            return;
        }

        $host = Host::query()->with(['client', 'product'])->find((int) $item->rel_id);
        if (!$host) {
            return;
        }

        if (!$this->canClientCreateBusiness($host)) {
            $this->logFailure($host, 'renew_paid', '客户账号状态不允许续费生效', $this->clientStateMeta($host));

            return;
        }

        if (in_array($host->status, ['Terminated', 'Cancelled'], true)) {
            $this->logFailure($host, 'renew_paid', '当前服务状态不允许续费生效', [
                'status' => $host->status,
                'invoice_item_id' => $item->id,
            ]);

            return;
        }

        $billingCycle = $this->renewalBillingCycle($item, $host);
        $base = $host->next_due_date && $host->next_due_date->isFuture() ? $host->next_due_date : now();
        $nextDueDate = $this->addCycle($base, $billingCycle);
        $host->update([
            'billing_cycle' => $billingCycle,
            'next_due_date' => $nextDueDate,
            'next_invoice_date' => $nextDueDate?->copy()->subDays(7),
        ]);
        if ($host->status === 'Suspended') {
            $this->unsuspend($host->fresh(['client', 'product']));
        }

        $this->log($host, 'renew_paid', '续费已生效', ['invoice_item_id' => $item->id]);
    }

    private function applyUpgradeItem(InvoiceItem $item): void
    {
        if ($this->invoiceItemAlreadyApplied($item, 'upgrade_completed')) {
            return;
        }

        $upgrade = Upgrade::query()->with('host.product')->find((int) $item->rel_id);
        if (!$upgrade || $upgrade->status === 'Completed') {
            return;
        }

        $host = $upgrade->host;
        $host->loadMissing(['client', 'product']);
        if (!$this->canClientCreateBusiness($host)) {
            $this->logFailure($host, $upgrade->type . '_completed', '客户账号状态不允许升级/降配生效', $this->clientStateMeta($host));

            return;
        }

        if ($host->status !== 'Active') {
            $this->logFailure($host, $upgrade->type . '_completed', '当前服务状态不允许升级/降配生效', [
                'status' => $host->status,
                'upgrade_id' => $upgrade->id,
            ]);

            return;
        }

        $this->completeUpgrade($upgrade);
        $upgrade->update([
            'status' => 'Completed',
            'completed_at' => now(),
        ]);
        $this->log($host, $upgrade->type . '_completed', '升级/降配已生效', [
            'invoice_item_id' => $item->id,
            'upgrade_id' => $upgrade->id,
        ]);
        $host->refresh()->loadMissing(['client', 'product']);
        $this->notifyClientSafely($host->client, 'host_upgrade_completed', [
            'client_name' => $host->client->username,
            'product_name' => $host->product?->name ?? '服务',
        ], 'host.upgrade_completed');
    }

    private function applyRechargeItem(Invoice $invoice, InvoiceItem $item): void
    {
        $invoice->loadMissing('client');
        if (!$invoice->client) {
            return;
        }

        $description = '充值：账单 ' . $invoice->invoice_number;
        if (Credit::query()
            ->where('client_id', $invoice->client_id)
            ->where('type', 'add')
            ->where('description', $description)
            ->exists()) {
            return;
        }

        app(ClientService::class)->addCredit(
            $invoice->client,
            (float) $item->amount,
            $description
        );
    }

    private function completeUpgrade(Upgrade $upgrade): void
    {
        $upgrade->loadMissing(['host.client']);
        $host = $upgrade->host;
        $targetProduct = Product::query()->findOrFail($upgrade->to_product_id);
        $targetAmount = $this->pricingService->calculatePrice($targetProduct, $host->billing_cycle, [
            'currency_id' => $host->client->currency_id,
        ]);

        $host->update([
            'product_id' => $upgrade->to_product_id,
            'recurring_amount' => round($targetAmount, 2),
        ]);
    }

    private function renewalBillingCycle(InvoiceItem $item, Host $host): string
    {
        $billingCycle = $item->meta['billing_cycle'] ?? null;

        if (is_string($billingCycle) && in_array($billingCycle, $this->availableCycles(), true)) {
            return $billingCycle;
        }

        return $host->billing_cycle;
    }

    private function invoiceItemAlreadyApplied(InvoiceItem $item, string $action): bool
    {
        return HostActionLog::query()
            ->where('action', $action)
            ->where('meta->invoice_item_id', $item->id)
            ->exists();
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

    private function canClientCreateBusiness(Host $host): bool
    {
        $client = $host->client;

        return $client !== null && !$client->trashed() && $client->isActive();
    }

    private function lockHostForOperation(Host $host): ?Host
    {
        return Host::query()
            ->with(['client', 'product', 'order.invoice'])
            ->whereKey($host->id)
            ->lockForUpdate()
            ->first();
    }

    private function isProvisionPaid(Host $host): bool
    {
        if (!$host->order_id) {
            return true;
        }

        $host->loadMissing('order.invoice');
        $order = $host->order;
        if (!$order || $order->status !== 'Paid') {
            return false;
        }

        return !$order->invoice || $order->invoice->status === 'Paid';
    }

    private function canClientReceiveServiceOperation(Host $host): bool
    {
        return $this->canClientCreateBusiness($host);
    }

    private function clientStateMeta(Host $host): array
    {
        return [
            'client_id' => $host->client_id,
            'client_status' => $host->client?->status,
            'client_deleted' => (bool) $host->client?->trashed(),
        ];
    }

    private function log(Host $host, string $action, ?string $message = null, array $meta = []): void
    {
        HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => $action,
            'message' => $message === null ? null : $this->maskSensitiveText($message),
            'meta' => $this->maskSensitive($meta),
        ]);
    }

    private function logFailure(Host $host, string $action, string $message, array $meta = []): void
    {
        $this->log($host, $action . '_failed', $message, $meta);
    }

    private function maskSensitive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? $this->maskSensitiveText($value) : $value;
        }

        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string) $key)) {
                $value[$key] = '[FILTERED]';

                continue;
            }

            $value[$key] = $this->maskSensitive($item);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1;
    }

    private function maskSensitiveText(string $value): string
    {
        foreach ([
            'password',
            'passwd',
            'secret',
            'token',
            'credential',
            'authorization',
            'cookie',
            'session',
            'bearer',
            'access_key',
            'private_key',
            'signature',
            'sign',
            'key',
        ] as $key) {
            $value = preg_replace(
                '/(' . preg_quote($key, '/') . ')\s*([=:])\s*([^\s,;]+)/i',
                '$1$2[FILTERED]',
                $value
            ) ?? $value;
        }

        return Str::limit($value, 2000, '...');
    }
}
