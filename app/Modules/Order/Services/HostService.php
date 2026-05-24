<?php

namespace App\Modules\Order\Services;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

            return Host::create([
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
                    return false;
                }

                $host->fill([
                    'username' => $result['username'] ?? $host->username,
                    'password' => $result['password'] ?? $host->password,
                    'server_id' => $result['server_id'] ?? $host->server_id,
                ]);
            }

            return $host->fill([
                'status' => 'Active',
                'registered_at' => $host->registered_at ?: now(),
                'suspend_reason' => null,
            ])->save();
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

            return $host->update([
                'status' => 'Suspended',
                'suspend_reason' => $reason,
            ]);
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

            return $host->update([
                'status' => 'Active',
                'suspend_reason' => null,
            ]);
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

            return $host->update([
                'status' => 'Terminated',
                'termination_date' => now(),
            ]);
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

            return $this->invoiceService->generate($host->client, [[
                'type' => 'product',
                'description' => $host->product->name . ' renewal (' . $billingCycle . ')',
                'amount' => $amount,
                'rel_id' => $host->id,
            ]]);
        });
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
            'config' => $host->notes,
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
}
