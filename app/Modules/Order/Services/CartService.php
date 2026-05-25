<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CartService
{
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

        $qty = max(1, (int) ($config['qty'] ?? 1));
        if (!$this->productService->isPurchasable($freshProduct, $qty)) {
            return null;
        }

        $cart = $this->getCart($freshClient);
        $billingCycle = (string) ($config['billing_cycle'] ?? 'monthly');
        $config['currency_id'] = (int) ($config['currency_id'] ?? $freshClient->currency_id ?? $this->pricingService->defaultCurrencyId());
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
        return Cache::get($this->cacheKey($client), ['items' => []]);
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

                    $qty = max(1, (int) ($item['qty'] ?? 1));
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

                $order = $this->orderService->create($client, $items);
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

    private function putCart(Client $client, array $cart): void
    {
        Cache::put($this->cacheKey($client), $cart, now()->addDays(7));
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
