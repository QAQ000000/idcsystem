<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_pay_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('gateway'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'manual_pay'));
        $this->assertTrue($manager->install('gateway', 'manual_pay'));
        $this->assertDatabaseHas('plugins', ['name' => 'manual_pay', 'type' => 'gateway', 'status' => 0]);

        $this->assertTrue($manager->enable('manual_pay'));
        $this->assertDatabaseHas('plugins', ['name' => 'manual_pay', 'status' => 1]);
        $this->assertTrue($manager->disable('manual_pay'));
        $this->assertDatabaseHas('plugins', ['name' => 'manual_pay', 'status' => 0]);
    }

    public function test_enabled_gateway_is_visible_on_client_invoice_page(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 88.00);

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('线下转账');
    }

    public function test_payment_service_process_payment_returns_manual_pay_result(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $this->assertTrue($result['success']);
        $this->assertSame('manual_pay', $result['gateway']);
        $this->assertSame($invoice->id, $result['invoice_id']);
    }

    public function test_payment_callback_marks_invoice_paid_when_amount_matches(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-001',
        ]);

        $this->assertTrue($result);
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('accounts', ['invoice_id' => $invoice->id, 'gateway_trans_id' => 'MANUAL-001']);
    }

    public function test_payment_callback_rejects_mismatched_amount(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 77.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-002',
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    private function installManualPay(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        Plugin::query()->where('name', 'manual_pay')->update([
            'config' => [
                'instructions' => '请转账到测试账户。',
                'bank_name' => '测试银行',
            ],
        ]);
    }

    private function client(): Client
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'pay-client',
            'email' => 'pay-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => 1,
        ]);
    }

    private function invoice(Client $client, float $amount): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-PAY-' . random_int(1000, 9999),
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
            'description' => '测试支付账单',
            'amount' => $amount,
        ]);

        return $invoice;
    }
}
