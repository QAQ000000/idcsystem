<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\PromoCode;
use App\Modules\Product\Models\CustomField;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CartService
{
    public const MAX_ITEM_QUANTITY = OrderService::MAX_ITEM_QUANTITY;

    private PricingService $pricingService;

    private OrderService $orderService;

    private ProductService $productService;

    public function __construct(?PricingService $pricingService = null, ?OrderService $orderService = null, ?ProductService $productService = null)
    {
        $this->pricingService = $pricingService ?? new PricingService();
        $this->orderService = $orderService ?? new OrderService();
        $this->productService = $productService ?? new ProductService();
    }

    /**
     * 添加商品到客户购物车。
     */
    public function add(Client $client, Product $product, array $config): ?array
    {
        $freshClient = Client::query()->whereKey($client->id)->first();
        $freshProduct = Product::query()->whereKey($product->id)->first();

        if (!$freshClient || !$freshProduct || !$this->canClientCreateBusiness($freshClient)) {
            return null;
        }

        $qty = $this->normalizeQuantity($config['qty'] ?? 1);
        if ($qty === null) {
            return null;
        }

        if (!$this->productService->isPurchasable($freshProduct, $qty)) {
            return null;
        }

        $cart = $this->getCart($freshClient);
        $billingCycle = (string) ($config['billing_cycle'] ?? 'monthly');
        $config['currency_id'] = (int) ($freshClient->currency_id ?: $this->pricingService->defaultCurrencyId());
        $price = $this->pricingService->calculatePrice($freshProduct, $billingCycle, $config);
        if ($price <= 0) {
            return null;
        }

        $cart['items'][] = [
            'id' => $this->nextItemId($cart),
            'product_id' => $freshProduct->id,
            'product_name' => $freshProduct->name,
            'billing_cycle' => $billingCycle,
            'qty' => $qty,
            'price' => $price,
            'config' => $config,
            'custom_field_labels' => $this->customFieldLabels($freshProduct, is_array($config['custom_fields'] ?? null) ? $config['custom_fields'] : []),
            'currency_id' => $config['currency_id'],
        ];

        $this->putCart($freshClient, $cart);

        return $cart;
    }

    /**
     * 从购物车移除商品。
     */
    public function remove(Client $client, int $itemId): bool
    {
        $cart = $this->getCart($client);
        $before = count($cart['items']);
        $cart['items'] = array_values(array_filter(
            $cart['items'],
            fn (array $item) => (string) $item['id'] !== (string) $itemId
        ));
        $this->putCart($client, $cart);

        return count($cart['items']) < $before;
    }

    /**
     * 获取购物车。
     */
    public function getCart(Client $client): array
    {
        $cart = Cache::get($this->cacheKey($client), ['items' => []]);
        $cart['items'] ??= [];

        return $this->withTotals($cart, $client);
    }

    /**
     * 清空购物车。
     */
    public function clear(Client $client): bool
    {
        return Cache::forget($this->cacheKey($client));
    }

    /**
     * 将购物车结算为订单。
     */
    public function checkout(Client $client): Order
    {
        return Cache::lock($this->checkoutLockKey($client), 10)->block(3, function () use ($client) {
            return DB::transaction(function () use ($client) {
                if (!$this->canClientCreateBusiness($client)) {
                    throw new RuntimeException('客户账号状态不允许结算。');
                }

                $cart = $this->getCart($client);
                $items = array_map(function (array $item) use ($client) {
                    $product = Product::query()
                        ->where('hidden', false)
                        ->where('retired', false)
                        ->find((int) $item['product_id']);

                    $qty = $this->normalizeQuantity($item['qty'] ?? 1);
                    if ($qty === null) {
                        throw new RuntimeException('购物车包含不可结算的商品，请移除后重试。');
                    }

                    if (!$product || !$this->productService->isPurchasable($product, $qty)) {
                        throw new RuntimeException('购物车包含不可结算的商品，请移除后重试。');
                    }

                    $billingCycle = (string) ($item['billing_cycle'] ?? 'monthly');
                    $item['currency_id'] = (int) ($item['currency_id'] ?? $client->currency_id ?? $this->pricingService->defaultCurrencyId());
                    $price = $this->pricingService->calculatePrice($product, $billingCycle, $item);
                    if ($price <= 0) {
                        throw new RuntimeException('购物车包含不可结算的商品，请移除后重试。');
                    }

                    return ($item['config'] ?? []) + [
                        'product_id' => $item['product_id'],
                        'billing_cycle' => $billingCycle,
                        'currency_id' => $item['currency_id'],
                        'qty' => $qty,
                        'price' => $price,
                    ];
                }, $cart['items']);

                if ($items === []) {
                    throw new RuntimeException('购物车没有可结算的有效商品。');
                }

                $promoCode = is_array($cart['promo'] ?? null) ? (string) ($cart['promo']['code'] ?? '') : null;
                $order = $this->orderService->create($client, $items, $promoCode ?: null);
                foreach ($items as $item) {
                    $product = Product::query()->find((int) $item['product_id']);
                    if (!$product || !$this->productService->decrementStock($product, (int) $item['qty'])) {
                        throw new RuntimeException('购物车包含不可结算的商品，请移除后重试。');
                    }
                }
                $this->clear($client);

                return $order;
            });
        });
    }

    public function applyPromoCode(Client $client, string $code): array
    {
        $cart = $this->getCart($client);
        if (($cart['items'] ?? []) === []) {
            throw new RuntimeException('购物车为空，无法使用优惠码。');
        }

        $promo = $this->validPromo($code);
        if (!$promo) {
            throw new RuntimeException('优惠码不可用或已过期。');
        }

        $discount = $this->calculateDiscount($cart, $promo);
        if ($discount <= 0) {
            throw new RuntimeException('优惠码不适用于当前购物车。');
        }

        $cart['promo'] = [
            'code' => $promo->code,
            'type' => $promo->type,
            'value' => (float) $promo->value,
            'discount' => $discount,
        ];
        $this->putCart($client, $cart);

        return $this->getCart($client);
    }

    public function removePromoCode(Client $client): void
    {
        $cart = $this->getCart($client);
        unset($cart['promo']);
        $this->putCart($client, $cart);
    }

    private function putCart(Client $client, array $cart): void
    {
        $cart['items'] ??= [];
        Cache::put($this->cacheKey($client), $cart, now()->addDays(7));
    }

    private function validPromo(string $code): ?PromoCode
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return PromoCode::query()
            ->where('code', $code)
            ->where('active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->where(function ($query) {
                $query->where('max_uses', '<=', 0)->orWhereColumn('used_count', '<', 'max_uses');
            })
            ->first();
    }

    private function calculateDiscount(array $cart, PromoCode $promo): float
    {
        $subtotal = round(array_sum(array_map(
            fn (array $item) => round((float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 1), 2),
            $cart['items'] ?? []
        )), 2);

        if ($subtotal <= 0) {
            return 0.0;
        }

        $eligibleSubtotal = $this->eligibleSubtotal($cart, $promo);
        if ($eligibleSubtotal <= 0) {
            return 0.0;
        }

        $rawDiscount = in_array($promo->type, ['percentage', 'percent'], true)
            ? $eligibleSubtotal * ((float) $promo->value / 100)
            : (float) $promo->value;

        return round(min($subtotal, $eligibleSubtotal, max(0, $rawDiscount)), 2);
    }

    private function eligibleSubtotal(array $cart, PromoCode $promo): float
    {
        $productIds = array_map('intval', $promo->product_ids ?? []);

        return round(array_sum(array_map(function (array $item) use ($promo, $productIds) {
            if ($promo->applies_to === 'products' && !in_array((int) ($item['product_id'] ?? 0), $productIds, true)) {
                return 0.0;
            }

            return round((float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 1), 2);
        }, $cart['items'] ?? [])), 2);
    }

    private function withTotals(array $cart, ?Client $client = null): array
    {
        $cart['items'] ??= [];
        $subtotal = round(array_sum(array_map(
            fn (array $item) => round((float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 1), 2),
            $cart['items']
        )), 2);

        $promoDiscount = 0.0;
        if (is_array($cart['promo'] ?? null)) {
            $promo = $this->validPromo((string) ($cart['promo']['code'] ?? ''));
            if ($promo) {
                $promoDiscount = $this->calculateDiscount($cart, $promo);
                if ($promoDiscount > 0) {
                    $cart['promo'] = [
                        'code' => $promo->code,
                        'type' => $promo->type,
                        'value' => (float) $promo->value,
                        'discount' => $promoDiscount,
                    ];
                } else {
                    unset($cart['promo']);
                }
            } else {
                unset($cart['promo']);
            }
        }

        $groupDiscount = $this->calculateClientGroupDiscount($client, $subtotal, $promoDiscount);
        if ($groupDiscount > 0 && $client) {
            $client->loadMissing('group');
            $cart['group_discount'] = [
                'name' => $client->group?->name,
                'percent' => (float) $client->group?->discount_percent,
                'discount' => $groupDiscount,
            ];
        } else {
            unset($cart['group_discount']);
        }

        $discount = round($promoDiscount + $groupDiscount, 2);
        $cart['totals'] = [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'promo_discount' => $promoDiscount,
            'group_discount' => $groupDiscount,
            'total' => round(max(0, $subtotal - $discount), 2),
        ];

        return $cart;
    }

    private function calculateClientGroupDiscount(?Client $client, float $subtotal, float $promoDiscount): float
    {
        if (!$client || $subtotal <= 0) {
            return 0.0;
        }

        $client->loadMissing('group');
        $percent = (float) ($client->group?->discount_percent ?? 0);
        if ($percent <= 0) {
            return 0.0;
        }

        $discountBase = max(0, $subtotal - $promoDiscount);

        return round(min($discountBase, $discountBase * ($percent / 100)), 2);
    }

    private function customFieldLabels(Product $product, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $fields = CustomField::query()
            ->where('type', 'product')
            ->where('rel_id', $product->id)
            ->where('admin_only', false)
            ->get()
            ->keyBy('id');
        $labels = [];

        foreach ($values as $fieldId => $value) {
            $field = $fields->get((int) $fieldId);
            if ($field) {
                $labels[$field->field_name] = (string) $value;
            }
        }

        return $labels;
    }

    private function normalizeQuantity(mixed $qty): ?int
    {
        if (!is_numeric($qty)) {
            return null;
        }

        $quantity = (int) $qty;

        if ((string) $quantity !== trim((string) $qty) || $quantity < 1 || $quantity > self::MAX_ITEM_QUANTITY) {
            return null;
        }

        return $quantity;
    }

    private function canClientCreateBusiness(Client $client): bool
    {
        return !$client->trashed() && $client->isActive();
    }

    private function cacheKey(Client $client): string
    {
        return 'cart:client:' . $client->id;
    }

    private function checkoutLockKey(Client $client): string
    {
        return 'cart:checkout:client:' . $client->id;
    }

    private function nextItemId(array $cart): int
    {
        $ids = array_map(fn (array $item) => (int) ($item['id'] ?? 0), $cart['items']);

        return $ids ? max($ids) + 1 : 1;
    }
}
