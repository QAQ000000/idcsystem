<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_login_and_read_me_with_bearer_token(): void
    {
        $client = $this->client();

        $login = $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'client123456',
            'device_name' => 'integration-test',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client.email', $client->email);

        $token = $login->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client.id', $client->id);
    }

    public function test_api_register_returns_token_and_client_payload(): void
    {
        $this->currency();

        $response = $this->postJson('/api/auth/register', [
            'username' => 'api-new-client',
            'email' => 'api-new-client@example.com',
            'password' => 'client123456',
            'privacy_policy' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client.email', 'api-new-client@example.com');

        $this->assertIsString($response->json('data.token'));
        $this->assertDatabaseHas('clients', [
            'email' => 'api-new-client@example.com',
            'status' => 1,
        ]);
        $this->assertDatabaseHas('privacy_policy_consents', [
            'policy_version' => config('app.privacy_policy_version', '1.0'),
        ]);
    }

    public function test_api_products_return_available_products_with_prices(): void
    {
        $client = $this->client();
        $product = $this->product();
        $hidden = $this->product(['name' => 'Hidden API Product', 'hidden' => true]);

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => $product->name])
            ->assertJsonMissing(['name' => $hidden->name])
            ->assertJsonPath('data.0.prices.monthly', 100);
    }

    public function test_api_protects_host_and_invoice_ownership(): void
    {
        $client = $this->client();
        $other = $this->client('api-other', 'api-other@example.com');
        $otherOrder = $this->order($other);
        $otherHost = $this->host($other, $otherOrder);
        $otherInvoice = $this->invoice($other, 'INV-API-OTHER');

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/hosts/' . $otherHost->id)
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/invoices/' . $otherInvoice->id)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_api_host_renew_creates_invoice_for_owner(): void
    {
        $client = $this->client();
        $product = $this->product();
        $order = $this->order($client);
        $host = $this->host($client, $order, [
            'product_id' => $product->id,
            'status' => 'Active',
            'billing_cycle' => 'monthly',
        ]);

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/hosts/' . $host->id . '/renew', [
                'billing_cycle' => 'monthly',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice.status', 'Unpaid')
            ->assertJsonPath('data.invoice.total', 100);

        $this->assertDatabaseHas('invoice_items', [
            'type' => 'renewal',
            'rel_id' => $host->id,
        ]);
    }

    public function test_api_account_credit_returns_available_credit_and_debt(): void
    {
        $client = $this->client();
        $client->update(['credit' => -30, 'credit_limit' => 100]);

        $this->actingAs($client->fresh(), 'sanctum')
            ->getJson('/api/account/credit')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.credit', -30)
            ->assertJsonPath('data.credit_limit', 100)
            ->assertJsonPath('data.available_credit', 70)
            ->assertJsonPath('data.debt', 30);
    }

    private function client(string $username = 'api-client', string $email = 'api-client@example.com'): Client
    {
        $currency = $this->currency();

        return Client::query()->create([
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function currency(): Currency
    {
        return Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
    }

    private function product(array $overrides = []): Product
    {
        $currency = $this->currency();
        $group = ProductGroup::query()->firstOrCreate(['name' => 'API 产品组']);
        $product = Product::query()->create(array_merge([
            'group_id' => $group->id,
            'name' => 'API VPS',
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
            'stock_qty' => 0,
        ], $overrides));

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $currency->id,
            'monthly' => 100,
            'quarterly' => 280,
            'semiannually' => 540,
            'annually' => 1000,
        ]);

        return $product;
    }

    private function order(Client $client): Order
    {
        return Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-API-' . $client->id . '-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 100,
            'currency_id' => $client->currency_id,
        ]);
    }

    private function host(Client $client, Order $order, array $overrides = []): Host
    {
        $product = isset($overrides['product_id'])
            ? Product::query()->findOrFail((int) $overrides['product_id'])
            : $this->product(['name' => 'API Host Product ' . random_int(1000, 9999)]);

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain' => 'api.example.com',
            'username' => 'apiuser',
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 100,
            'recurring_amount' => 100,
            'status' => 'Active',
        ], $overrides));
    }

    private function invoice(Client $client, string $number): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => $number,
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'status' => 'Unpaid',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'type' => 'product',
            'description' => 'API invoice item',
            'amount' => 100,
        ]);

        return $invoice;
    }
}
