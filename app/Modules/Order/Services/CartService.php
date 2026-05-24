<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\Cache;

class CartService
{
    private PricingService $pricingService;

    private OrderService $orderService;

    public function __construct(?PricingService $pricingService = null, ?OrderService $orderService = null)
    {
        $this->pricingService = $pricingService ?? new PricingService();
        $this->orderService = $orderService ?? new OrderService();
    }

    /**
     * 添加商品到客户购物车。
     */
    public function add(Client $client, Product $product, array $config): array
    {
        $cart = $this->getCart($client);
        $billingCycle = (string) ($config['billing_cycle'] ?? 'monthly');
        $cart['items'][] = [
            'id' => $this->nextItemId($cart),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => $billingCycle,
            'qty' => max(1, (int) ($config['qty'] ?? 1)),
            'price' => $this->pricingService->calculatePrice($product, $billingCycle, $config),
            'config' => $config,
        ];

        $this->putCart($client, $cart);

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
        $cart = $this->getCart($client);
        $items = array_map(function (array $item) {
            return ($item['config'] ?? []) + [
                'product_id' => $item['product_id'],
                'billing_cycle' => $item['billing_cycle'],
                'qty' => $item['qty'],
            ];
        }, $cart['items']);

        $order = $this->orderService->create($client, $items);
        $this->clear($client);

        return $order;
    }

    private function putCart(Client $client, array $cart): void
    {
        Cache::put($this->cacheKey($client), $cart, now()->addDays(7));
    }

    private function cacheKey(Client $client): string
    {
        return 'cart:client:' . $client->id;
    }

    private function nextItemId(array $cart): int
    {
        $ids = array_map(fn (array $item) => (int) ($item['id'] ?? 0), $cart['items']);

        return $ids ? max($ids) + 1 : 1;
    }
}
