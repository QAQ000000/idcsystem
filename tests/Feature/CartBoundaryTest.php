<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Modules\Order\Services\CartService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CartBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_product_cannot_be_added_to_cart(): void
    {
        $client = $this->client();
        $product = $this->product(['hidden' => true]);

        $this->actingAs($client, 'client')
            ->post(route('client.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertNotFound();
    }

    public function test_hidden_retired_or_out_of_stock_product_detail_is_not_public(): void
    {
        $hidden = $this->product(['hidden' => true]);
        $retired = $this->product(['retired' => true]);
        $outOfStock = $this->product(['stock_control' => true, 'stock_qty' => 0]);

        $this->get(route('client.products.show', $hidden))->assertNotFound();
        $this->get(route('client.products.show', $retired))->assertNotFound();
        $this->get(route('client.products.show', $outOfStock))->assertNotFound();
    }

    public function test_out_of_stock_product_cannot_be_added_to_cart(): void
    {
        $client = $this->client();
        $product = $this->product(['stock_control' => true, 'stock_qty' => 0]);

        $this->actingAs($client, 'client')
            ->post(route('client.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertNotFound();
    }

    public function test_invalid_billing_cycle_cannot_be_added_to_cart(): void
    {
        $client = $this->client();
        $product = $this->product();

        $this->actingAs($client, 'client')
            ->post(route('client.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'invalid-cycle',
            ])
            ->assertSessionHasErrors('billing_cycle');
    }

    public function test_cart_add_rejects_quantity_above_limit_from_http(): void
    {
        $client = $this->client();
        $product = $this->product();

        $this->actingAs($client, 'client')
            ->post(route('client.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'qty' => 101,
            ])
            ->assertSessionHasErrors('qty');

        $this->assertSame([], app(CartService::class)->getCart($client)['items']);
    }

    public function test_cart_service_rejects_quantity_above_limit(): void
    {
        $client = $this->client();
        $product = $this->product();

        $this->assertNull(app(CartService::class)->add($client, $product, [
            'billing_cycle' => 'monthly',
            'qty' => 101,
        ]));

        $this->assertSame([], app(CartService::class)->getCart($client)['items']);
    }

    public function test_cart_service_rejects_fractional_quantity(): void
    {
        $client = $this->client();
        $product = $this->product();

        $this->assertNull(app(CartService::class)->add($client, $product, [
            'billing_cycle' => 'monthly',
            'qty' => '1.5',
        ]));

        $this->assertSame([], app(CartService::class)->getCart($client)['items']);
    }

    public function test_checkout_rejects_cached_cart_item_quantity_above_limit(): void
    {
        $client = $this->client();
        $product = $this->product();
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, ['billing_cycle' => 'monthly']));
        $cached = $cart->getCart($client);
        $cached['items'][0]['qty'] = 101;
        \Illuminate\Support\Facades\Cache::put('cart:client:' . $client->id, $cached, now()->addDays(7));

        try {
            $cart->checkout($client);
            $this->fail('Expected cart checkout to reject quantity above limit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('购物车包含不可结算的商品，请移除后重试。', $exception->getMessage());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, count($cart->getCart($client)['items']));
    }

    public function test_checkout_rejects_cached_cart_item_fractional_quantity(): void
    {
        $client = $this->client();
        $product = $this->product();
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, ['billing_cycle' => 'monthly']));
        $cached = $cart->getCart($client);
        $cached['items'][0]['qty'] = '1.5';
        \Illuminate\Support\Facades\Cache::put('cart:client:' . $client->id, $cached, now()->addDays(7));

        try {
            $cart->checkout($client);
            $this->fail('Expected cart checkout to reject fractional quantity.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('购物车包含不可结算的商品，请移除后重试。', $exception->getMessage());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, count($cart->getCart($client)['items']));
    }

    public function test_checkout_rejects_cart_when_item_becomes_hidden(): void
    {
        $client = $this->client();
        $product = $this->product();
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, ['billing_cycle' => 'monthly']));
        $product->update(['hidden' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('购物车包含不可结算的商品，请移除后重试。');

        $cart->checkout($client);
    }

    public function test_checkout_rejects_cart_when_stock_is_exhausted_after_add(): void
    {
        $client = $this->client();
        $product = $this->product(['stock_control' => true, 'stock_qty' => 1]);
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, ['billing_cycle' => 'monthly']));
        $product->update(['stock_qty' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('购物车包含不可结算的商品，请移除后重试。');

        $cart->checkout($client);
    }

    public function test_checkout_decrements_stock_for_stock_controlled_product(): void
    {
        $client = $this->client();
        $product = $this->product(['stock_control' => true, 'stock_qty' => 2]);
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, [
            'billing_cycle' => 'monthly',
            'qty' => 2,
        ]));

        $order = $cart->checkout($client);

        $this->assertSame('Pending', $order->status);
        $this->assertSame(0, (int) $product->fresh()->stock_qty);
    }

    public function test_checkout_creates_one_host_for_each_quantity(): void
    {
        $client = $this->client();
        $product = $this->product(['stock_control' => true, 'stock_qty' => 2]);
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, [
            'billing_cycle' => 'monthly',
            'qty' => 2,
        ]));

        $order = $cart->checkout($client);

        $this->assertSame(2, $order->hosts()->count());
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'amount' => 100,
        ]);
    }

    public function test_cart_uses_client_currency_for_price_and_order_amount(): void
    {
        $cny = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $usd = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            ['prefix' => '$', 'suffix' => '', 'exchange_rate' => 7, 'is_default' => false]
        );
        $client = $this->client();
        $client->update(['currency_id' => $usd->id]);
        $product = $this->product();
        Pricing::query()->updateOrCreate(
            ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $cny->id],
            ['monthly' => 50]
        );
        Pricing::query()->updateOrCreate(
            ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $usd->id],
            ['monthly' => 9]
        );

        $cart = app(CartService::class);
        $cart->add($client->fresh(), $product, ['billing_cycle' => 'monthly']);
        $cartItem = $cart->getCart($client->fresh())['items'][0];

        $this->assertSame(9.0, $cartItem['price']);
        $this->assertSame($usd->id, $cartItem['currency_id']);

        $order = $cart->checkout($client->fresh());

        $this->assertSame($usd->id, $order->currency_id);
        $this->assertSame('9.00', (string) $order->amount);
        $this->assertSame('9.00', (string) $order->invoice->fresh()->total);
        $this->assertSame('9.00', (string) $order->hosts()->firstOrFail()->first_payment_amount);
    }

    public function test_product_detail_uses_logged_in_client_currency(): void
    {
        $usd = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            ['prefix' => '$', 'suffix' => '', 'exchange_rate' => 7, 'is_default' => false]
        );
        $client = $this->client();
        $client->update(['currency_id' => $usd->id]);
        $product = $this->product();
        Pricing::query()->updateOrCreate(
            ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $usd->id],
            ['monthly' => 9, 'quarterly' => 25, 'semiannually' => 48, 'annually' => 90]
        );

        $this->actingAs($client->fresh(), 'client')
            ->get(route('client.products.show', $product))
            ->assertOk()
            ->assertSee('$9.00')
            ->assertDontSee('¥50.00');
    }

    public function test_checkout_rejects_cart_when_product_price_becomes_invalid_after_add(): void
    {
        $client = $this->client();
        $product = $this->product();
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, ['billing_cycle' => 'monthly']));
        app(PricingService::class)->setPricing('product', $product->id, Currency::query()->where('code', 'CNY')->value('id'), [
            'monthly' => -1,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('购物车包含不可结算的商品，请移除后重试。');

        $cart->checkout($client);
    }

    public function test_checkout_rejects_mixed_cart_when_any_item_becomes_invalid_and_keeps_cart(): void
    {
        $client = $this->client();
        $valid = $this->product(['name' => 'Valid Product']);
        $hidden = $this->product(['name' => 'Hidden Later Product']);
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $valid, ['billing_cycle' => 'monthly']));
        $this->assertNotNull($cart->add($client, $hidden, ['billing_cycle' => 'monthly']));
        $hidden->update(['hidden' => true]);

        try {
            $cart->checkout($client);
            $this->fail('Expected mixed invalid cart checkout to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('购物车包含不可结算的商品，请移除后重试。', $exception->getMessage());
        }

        $this->assertSame(2, count($cart->getCart($client)['items']));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_client_checkout_redirects_back_when_cart_becomes_invalid(): void
    {
        $client = $this->client();
        $product = $this->product();
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, ['billing_cycle' => 'monthly']));
        app(PricingService::class)->setPricing('product', $product->id, Currency::query()->where('code', 'CNY')->value('id'), [
            'monthly' => -1,
        ]);

        $this->actingAs($client, 'client')
            ->post(route('client.cart.checkout'))
            ->assertRedirect(route('client.cart.index'))
            ->assertSessionHasErrors('checkout');
    }

    public function test_inactive_client_cannot_add_or_checkout_cart(): void
    {
        $client = $this->client();
        $product = $this->product();
        $cart = app(CartService::class);

        $client->update(['status' => 2]);

        $this->assertNull($cart->add($client->fresh(), $product, ['billing_cycle' => 'monthly']));
        $this->assertSame([], $cart->getCart($client)['items']);

        $client->update(['status' => 1]);
        $this->assertNotNull($cart->add($client->fresh(), $product, ['billing_cycle' => 'monthly']));
        $client->update(['status' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('客户账号状态不允许结算');

        $cart->checkout($client->fresh());
    }

    public function test_cart_add_rechecks_latest_client_status(): void
    {
        $client = $this->client();
        $staleClient = $client->fresh();
        $product = $this->product();
        $client->update(['status' => 2]);

        $this->assertNull(app(CartService::class)->add($staleClient, $product, ['billing_cycle' => 'monthly']));
        $this->assertSame([], app(CartService::class)->getCart($client)['items']);
    }

    public function test_cart_add_rechecks_latest_product_state(): void
    {
        $client = $this->client();
        $product = $this->product();
        $staleProduct = $product->fresh();
        $product->update(['hidden' => true]);

        $this->assertNull(app(CartService::class)->add($client, $staleProduct, ['billing_cycle' => 'monthly']));
        $this->assertSame([], app(CartService::class)->getCart($client)['items']);
    }

    public function test_order_service_rejects_inactive_client_order_creation(): void
    {
        $client = $this->client();
        $product = $this->product();
        $client->update(['status' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('客户账号状态不允许创建订单');

        app(OrderService::class)->create($client->fresh(), [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);
    }

    public function test_order_service_rechecks_latest_client_status_before_creation(): void
    {
        $client = $this->client();
        $staleClient = $client->fresh();
        $product = $this->product();
        $client->update(['status' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('客户账号状态不允许创建订单');

        app(OrderService::class)->create($staleClient, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $staleClient->currency_id,
        ]]);
    }

    public function test_order_service_rejects_hidden_product_order_creation(): void
    {
        $client = $this->client();
        $product = $this->product(['hidden' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订单包含不可购买商品');

        app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);
    }

    public function test_order_service_rechecks_latest_product_state_when_model_is_passed(): void
    {
        $client = $this->client();
        $product = $this->product();
        $staleProduct = $product->fresh();
        $product->update(['hidden' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订单包含不可购买商品');

        app(OrderService::class)->create($client, [[
            'product' => $staleProduct,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);
    }

    public function test_order_service_rejects_invalid_price_order_creation(): void
    {
        $client = $this->client();
        $product = $this->product();
        app(PricingService::class)->setPricing('product', $product->id, $client->currency_id, [
            'monthly' => -1,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订单商品价格无效');

        app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);
    }

    public function test_order_service_uses_client_currency_even_when_currency_id_is_missing(): void
    {
        $cny = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $usd = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            ['prefix' => '$', 'suffix' => '', 'exchange_rate' => 7, 'is_default' => false]
        );
        $client = $this->client();
        $client->update(['currency_id' => $usd->id]);
        $product = $this->product();
        Pricing::query()
            ->where('type', 'product')
            ->where('rel_id', $product->id)
            ->where('currency_id', $cny->id)
            ->update(['monthly' => 50]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订单商品价格无效');

        app(OrderService::class)->create($client->fresh(), [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
        ]]);
    }

    public function test_order_service_ignores_spoofed_currency_id_and_uses_client_currency(): void
    {
        $cny = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $usd = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            ['prefix' => '$', 'suffix' => '', 'exchange_rate' => 7, 'is_default' => false]
        );
        $client = $this->client();
        $client->update(['currency_id' => $usd->id]);
        $product = $this->product();
        Pricing::query()->updateOrCreate(
            ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $cny->id],
            ['monthly' => 50]
        );
        Pricing::query()->updateOrCreate(
            ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $usd->id],
            ['monthly' => 9]
        );

        $order = app(OrderService::class)->create($client->fresh(), [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $cny->id,
        ]]);

        $this->assertSame($usd->id, (int) $order->currency_id);
        $this->assertSame('9.00', (string) $order->amount);
        $this->assertSame('9.00', (string) $order->invoice->fresh()->total);
    }

    public function test_order_service_rejects_quantity_above_limit(): void
    {
        $client = $this->client();
        $product = $this->product();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订单商品数量无效');

        app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
            'qty' => 101,
        ]]);
    }

    public function test_order_service_rejects_fractional_quantity(): void
    {
        $client = $this->client();
        $product = $this->product();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订单商品数量无效');

        app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
            'qty' => '1.5',
        ]]);
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'cart-client-' . random_int(1000, 9999),
            'email' => 'cart-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $group = ProductGroup::query()->firstOrCreate(['name' => '购物车边界产品']);
        $product = Product::query()->create(array_merge([
            'group_id' => $group->id,
            'name' => 'Cart VPS ' . random_int(1000, 9999),
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
            'stock_qty' => 0,
        ], $overrides));

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => 50,
        ]);

        return $product;
    }
}
