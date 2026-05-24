<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Modules\Order\Services\CartService;
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

    public function test_checkout_rejects_cart_when_item_becomes_hidden(): void
    {
        $client = $this->client();
        $product = $this->product();
        $cart = app(CartService::class);

        $this->assertNotNull($cart->add($client, $product, ['billing_cycle' => 'monthly']));
        $product->update(['hidden' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('购物车没有可结算的有效商品');

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
        $this->expectExceptionMessage('购物车没有可结算的有效商品');

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
