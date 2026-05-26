<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_locale_switches_frontend_language_and_persists_session(): void
    {
        $this->get(route('client.login', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('Client Login')
            ->assertSee('Products')
            ->assertSessionHas('locale', 'en');

        $this->get(route('client.register'))
            ->assertOk()
            ->assertSee('Client Registration');
    }

    public function test_client_locale_prefers_profile_setting_and_can_be_updated(): void
    {
        $client = $this->client(['locale' => 'en']);

        $this->actingAs($client, 'client')
            ->get(route('client.account.profile'))
            ->assertOk()
            ->assertSee('Account Profile')
            ->assertSee('Interface Language');

        $this->actingAs($client, 'client')
            ->put(route('client.account.profile.update'), [
                'currency_id' => $client->currency_id,
                'locale' => 'zh_CN',
            ])
            ->assertRedirect(route('client.account.profile'))
            ->assertSessionHas('locale', 'zh_CN')
            ->assertSessionHas('status', '资料已更新');

        $this->assertSame('zh_CN', $client->fresh()->locale);
    }

    public function test_key_frontend_pages_render_english_translations(): void
    {
        $client = $this->client(['locale' => 'en']);
        $product = $this->product();

        $this->actingAs($client, 'client')
            ->get(route('client.products.index'))
            ->assertOk()
            ->assertSee('Products')
            ->assertSee('Monthly');

        $this->actingAs($client, 'client')
            ->get(route('client.products.show', $product))
            ->assertOk()
            ->assertSee('Billing Cycle')
            ->assertSee('Add to Cart');

        $this->actingAs($client, 'client')
            ->get(route('client.cart.index'))
            ->assertOk()
            ->assertSee('Cart')
            ->assertSee('Promo Code');

        $this->actingAs($client, 'client')
            ->get(route('client.hosts.index'))
            ->assertOk()
            ->assertSee('My Services');

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.index'))
            ->assertOk()
            ->assertSee('My Invoices');

        $this->actingAs($client, 'client')
            ->get(route('client.tickets.index'))
            ->assertOk()
            ->assertSee('My Tickets');
    }

    private function client(array $overrides = []): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create(array_merge([
            'username' => 'locale-client-' . random_int(1000, 9999),
            'email' => 'locale-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
            'locale' => 'zh_CN',
        ], $overrides));
    }

    private function product(): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => 'Localization Products']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Locale VPS',
            'description' => 'Locale test product',
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => 50,
        ]);

        return $product;
    }
}
