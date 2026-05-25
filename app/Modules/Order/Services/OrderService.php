<?php

namespace App\Modules\Order\Services;

use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OrderService
{
    private PricingService $pricingService;

    private InvoiceService $invoiceService;

    public function __construct(?PricingService $pricingService = null, ?InvoiceService $invoiceService = null)
    {
        $this->pricingService = $pricingService ?? new PricingService();
        $this->invoiceService = $invoiceService ?? new InvoiceService();
    }

    /**
     * 创建订单、账单和对应的待开通服务。
     */
    public function create(Client $client, array $items): Order
    {
        return DB::transaction(function () use ($client, $items) {
            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->first();
            if (!$lockedClient || $lockedClient->trashed() || !$lockedClient->isActive()) {
                throw new RuntimeException('客户账号状态不允许创建订单。');
            }

            $this->assertOrderItemsPurchasable($items);

            $totals = $this->calculateTotalForClient($items, null, $lockedClient);
            $order = Order::create([
                'client_id' => $lockedClient->id,
                'order_number' => $this->nextOrderNumber(),
                'status' => 'Pending',
                'amount' => $totals['total'],
                'currency_id' => (int) ($totals['currency_id'] ?? $lockedClient->currency_id ?? 1),
                'promo_code' => $totals['promo_code'],
                'promo_value' => $totals['discount'],
            ]);

            $invoice = $this->invoiceService->generate($lockedClient, $totals['invoice_items']);
            $order->update(['invoice_id' => $invoice->id]);

            $hostService = new HostService($this->pricingService, $this->invoiceService);
            foreach ($items as $item) {
                $product = $this->resolveProduct($item);
                $quantity = max(1, (int) ($item['qty'] ?? 1));

                for ($i = 0; $i < $quantity; $i++) {
                    $hostService->create($order, $product, $item);
                }
            }

            return $order->fresh(['hosts']);
        });
    }

    /**
     * 计算订单总价，返回小计、优惠、总计和账单项目。
     */
    public function calculateTotal(array $items, ?string $promoCode = null): array
    {
        return $this->calculateTotalForClient($items, $promoCode);
    }

    private function calculateTotalForClient(array $items, ?string $promoCode = null, ?Client $client = null): array
    {
        $subtotal = 0.0;
        $currencyId = null;
        $invoiceItems = [];

        foreach ($items as $item) {
            $product = $this->resolveProduct($item);
            $billingCycle = (string) ($item['billing_cycle'] ?? 'monthly');
            $quantity = max(1, (int) ($item['qty'] ?? 1));
            $price = $this->pricingService->calculatePrice($product, $billingCycle, $item);
            if ($price <= 0) {
                throw new RuntimeException('订单商品价格无效，不能创建订单。');
            }

            $lineTotal = round($price * $quantity, 2);
            $subtotal += $lineTotal;
            $currencyId ??= (int) ($item['currency_id'] ?? $client?->currency_id ?? $this->pricingService->defaultCurrencyId());

            $invoiceItems[] = [
                'type' => 'product',
                'description' => $product->name . ' (' . $billingCycle . ')',
                'amount' => $lineTotal,
                'rel_id' => $product->id,
            ];
        }

        $discount = $this->calculatePromoDiscount($subtotal, $promoCode);

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'total' => round(max(0, $subtotal - $discount), 2),
            'currency_id' => $currencyId ?? $this->pricingService->defaultCurrencyId(),
            'promo_code' => $promoCode,
            'invoice_items' => $invoiceItems,
        ];
    }

    /**
     * 标记订单已支付，并同步账单和待开通服务。
     */
    public function markAsPaid(Order $order, string $paymentMethod, string $transId): bool
    {
        return DB::transaction(function () use ($order, $paymentMethod, $transId) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (!$lockedOrder || $lockedOrder->status !== 'Pending') {
                return false;
            }

            $invoice = $lockedOrder->invoice()->lockForUpdate()->first();
            if ($invoice) {
                return $this->invoiceService->markAsPaid($invoice, $paymentMethod, $transId);
            }

            $lockedOrder->update([
                'status' => 'Paid',
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * 取消订单。
     */
    public function cancel(Order $order, string $reason): bool
    {
        return DB::transaction(function () use ($order, $reason) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (!$lockedOrder || $lockedOrder->status !== 'Pending') {
                return false;
            }

            $lockedOrder->update([
                'status' => 'Cancelled',
                'admin_notes' => trim(($lockedOrder->admin_notes ? $lockedOrder->admin_notes . PHP_EOL : '') . $reason),
            ]);

            $lockedOrder->hosts()->where('status', 'Pending')->update([
                'status' => 'Cancelled',
                'admin_notes' => $reason,
            ]);

            $invoice = $lockedOrder->invoice()->lockForUpdate()->first();
            if ($invoice && $invoice->status === 'Unpaid') {
                $invoice->update([
                    'status' => 'Cancelled',
                    'notes' => trim(($invoice->notes ? $invoice->notes . PHP_EOL : '') . $reason),
                ]);
            }

            return true;
        });
    }

    private function resolveProduct(array $item): Product
    {
        if (($item['product'] ?? null) instanceof Product) {
            return Product::query()->findOrFail($item['product']->id);
        }

        return Product::query()->findOrFail((int) ($item['product_id'] ?? 0));
    }

    private function assertOrderItemsPurchasable(array $items): void
    {
        if ($items === []) {
            throw new RuntimeException('订单没有可购买商品。');
        }

        foreach ($items as $item) {
            $product = $this->resolveProduct($item);
            $quantity = max(1, (int) ($item['qty'] ?? 1));

            if (!$this->productService()->isPurchasable($product, $quantity)) {
                throw new RuntimeException('订单包含不可购买商品。');
            }
        }
    }

    private function productService(): ProductService
    {
        return app(ProductService::class);
    }

    private function calculatePromoDiscount(float $subtotal, ?string $promoCode): float
    {
        if (!$promoCode) {
            return 0.0;
        }

        $promo = DB::table('promo_codes')
            ->where('code', $promoCode)
            ->where('active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->first();

        if (!$promo || ((int) $promo->max_uses > 0 && (int) $promo->used_count >= (int) $promo->max_uses)) {
            return 0.0;
        }

        return $promo->type === 'percentage'
            ? $subtotal * ((float) $promo->value / 100)
            : min($subtotal, (float) $promo->value);
    }

    private function nextOrderNumber(): string
    {
        return 'ORD' . now()->format('YmdHis') . Str::upper(Str::random(4));
    }
}
