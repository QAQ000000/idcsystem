<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\CreditScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreditScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_invoice_refund_and_overdue_suspend_adjust_credit_score(): void
    {
        Queue::fake();
        $client = $this->client();
        $invoice = $this->invoice($client, 120);

        $this->assertTrue(app(InvoiceService::class)->markAsPaid($invoice, 'manual', 'CREDIT-SCORE-PAID-1'));
        $this->assertSame(102, $client->fresh()->credit_score);
        $this->assertDatabaseHas('credit_score_logs', [
            'client_id' => $client->id,
            'reason' => 'payment_success',
            'old_score' => 100,
            'new_score' => 102,
        ]);

        $this->assertFalse(app(InvoiceService::class)->markAsPaid($invoice->fresh(), 'manual', 'CREDIT-SCORE-PAID-2'));
        $this->assertSame(1, $client->creditScoreLogs()->where('reason', 'payment_success')->count());

        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 20));
        $this->assertSame(99, $client->fresh()->credit_score);

        $host = $this->host($client);
        $this->assertSame(1, app(BillingService::class)->suspendOverdueHosts());
        $this->assertSame(94, $client->fresh()->credit_score);
        $this->assertSame('Excellent', $client->fresh()->credit_level);
    }

    public function test_recalculate_command_and_poor_credit_order_approval_flag(): void
    {
        $client = $this->client();
        app(CreditScoreService::class)->updateScore($client, 'manual_test', ['score' => 40]);
        $product = $this->pricedProduct(1200);

        $order = app(OrderService::class)->create($client->fresh(), [[
            'product' => $product,
            'billing_cycle' => 'monthly',
        ]]);

        $this->assertTrue($order->requires_approval);
        $this->assertSame('Pending', $order->status);

        $this->artisan('credit:recalculate-scores')
            ->assertExitCode(0);
        $this->assertSame(100, $client->fresh()->credit_score);
    }

    private function client(): Client
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'credit-client-' . random_int(1000, 9999),
            'email' => 'credit-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => 1,
        ]);
    }

    private function invoice(Client $client, float $amount): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-CREDIT-' . random_int(1000, 9999),
            'subtotal' => $amount,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => $amount,
            'status' => 'Unpaid',
            'due_date' => now()->addDays(7),
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'type' => 'product',
            'description' => '信用评分测试账单',
            'amount' => $amount,
        ]);

        return $invoice;
    }

    private function host(Client $client): Host
    {
        $product = $this->pricedProduct(100);
        $order = \App\Modules\Order\Models\Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-CREDIT-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 100,
            'currency_id' => 1,
            'invoice_id' => 0,
        ]);

        return Host::query()->create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 100,
            'recurring_amount' => 100,
            'status' => 'Active',
            'auto_renew' => true,
            'next_due_date' => now()->subDays(3),
        ]);
    }

    private function pricedProduct(float $monthly): \App\Modules\Product\Models\Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '信用评分产品']);
        $product = app(ProductService::class)->create([
            'group_id' => $group->id,
            'name' => '信用评分产品 ' . random_int(1000, 9999),
            'type' => 'hosting',
            'pay_type' => 'recurring',
            'pay_method' => 'prepaid',
            'auto_setup' => 'manual',
            'stock_control' => false,
            'hidden' => false,
            'retired' => false,
        ]);

        app(PricingService::class)->setPricing('product', $product->id, 1, [
            'monthly' => $monthly,
        ]);

        return $product;
    }
}
