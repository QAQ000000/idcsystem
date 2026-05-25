<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_index_filters_invoices_hosts_and_products_and_lists_gateways(): void
    {
        $client = $this->client();
        $product = $this->product();
        $invoice = $this->invoice($client, 'INV-FILTER-1');
        $otherInvoice = $this->invoice($client, 'INV-FILTER-2', 'Paid');

        Plugin::query()->create([
            'name' => 'manualpay',
            'title' => 'Manual Pay',
            'type' => 'gateway',
            'version' => '1.0.0',
            'author' => 'IDC',
            'status' => 1,
            'config' => [],
        ]);

        $this->withToken($client->createToken('api')->plainTextToken)
            ->getJson('/api/products?type=' . $product->type)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => $product->name]);

        $this->withToken($client->createToken('api-2')->plainTextToken)
            ->getJson('/api/invoices?status=Unpaid')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['invoice_number' => $invoice->invoice_number])
            ->assertJsonMissing(['invoice_number' => $otherInvoice->invoice_number]);

        $this->withToken($client->createToken('api-3')->plainTextToken)
            ->getJson('/api/payment/gateways')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'manualpay');
    }

    public function test_api_can_pay_invoice_with_credit_and_create_recharge_invoice(): void
    {
        $client = $this->client();
        $client->update(['credit' => 120, 'credit_limit' => 50]);
        $invoice = $this->invoice($client, 'INV-PAY-1');
        $invoice->update(['subtotal' => 70, 'total' => 70]);

        $token = $client->createToken('pay-client')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/invoices/{$invoice->id}/pay-with-credit")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice.status', 'Paid');

        $this->assertSame('Paid', $invoice->fresh()->status);

        $response = $this->withToken($token)
            ->postJson('/api/account/recharge', ['amount' => 88])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(88.0, (float) $response->json('data.amount'));
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'api-pay-client',
            'email' => 'api-pay-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(): Product
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $group = ProductGroup::query()->firstOrCreate(['name' => 'API 支付分组']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'API Pay VPS',
            'type' => 'hosting',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
            'stock_qty' => 0,
        ]);

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

    private function invoice(Client $client, string $number, string $status = 'Unpaid'): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => $number,
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'status' => $status,
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
