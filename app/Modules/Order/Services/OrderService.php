<?php

namespace App\Modules\Order\Services;

use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $totals = $this->calculateTotal($items);
            $order = Order::create([
                'client_id' => $client->id,
                'order_number' => $this->nextOrderNumber(),
                'status' => 'Pending',
                'amount' => $totals['total'],
                'currency_id' => (int) ($totals['currency_id'] ?? $client->currency_id ?? 1),
                'promo_code' => $totals['promo_code'],
                'promo_value' => $totals['discount'],
            ]);

            $invoice = $this->invoiceService->generate($client, $totals['invoice_items']);
            $order->update(['invoice_id' => $invoice->id]);

            $hostService = new HostService($this->pricingService, $this->invoiceService);
            foreach ($items as $item) {
                $product = $this->resolveProduct($item);
                $hostService->create($order, $product, $item);
            }

            return $order->fresh(['hosts']);
        });
    }

    /**
     * 计算订单总价，返回小计、优惠、总计和账单项目。
     */
    public function calculateTotal(array $items, ?string $promoCode = null): array
    {
        $subtotal = 0.0;
        $currencyId = null;
        $invoiceItems = [];

        foreach ($items as $item) {
            $product = $this->resolveProduct($item);
            $billingCycle = (string) ($item['billing_cycle'] ?? 'monthly');
            $quantity = max(1, (int) ($item['qty'] ?? 1));
            $price = $this->pricingService->calculatePrice($product, $billingCycle, $item);
            $lineTotal = round($price * $quantity, 2);
            $subtotal += $lineTotal;
            $currencyId ??= (int) ($item['currency_id'] ?? $this->pricingService->defaultCurrencyId());

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
            $order->update([
                'status' => 'Paid',
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
            ]);

            if ($order->invoice) {
                $this->invoiceService->markAsPaid($order->invoice, $paymentMethod, $transId);
            }

            foreach ($order->hosts as $host) {
                if ($host->status === 'Pending') {
                    (new HostService($this->pricingService, $this->invoiceService))->provision($host);
                }
            }

            return true;
        });
    }

    /**
     * 取消订单。
     */
    public function cancel(Order $order, string $reason): bool
    {
        return DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status' => 'Cancelled',
                'admin_notes' => trim(($order->admin_notes ? $order->admin_notes . PHP_EOL : '') . $reason),
            ]);

            $order->hosts()->where('status', 'Pending')->update([
                'status' => 'Cancelled',
                'admin_notes' => $reason,
            ]);

            return true;
        });
    }

    private function resolveProduct(array $item): Product
    {
        if (($item['product'] ?? null) instanceof Product) {
            return $item['product'];
        }

        return Product::query()->findOrFail((int) ($item['product_id'] ?? 0));
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
