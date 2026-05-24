<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefundRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
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

    public function test_payment_service_reuses_pending_payment_attempt_for_same_invoice_gateway(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);
        $service = app(PaymentService::class);

        $first = $service->processPayment($invoice, 'manual_pay', []);
        $second = $service->processPayment($invoice->fresh(), 'manual_pay', []);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['reused'] ?? false);
        $this->assertSame(1, PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->where('status', 'pending')
            ->count());
    }

    public function test_payment_service_reuses_failed_payment_attempt_for_same_invoice_gateway(): void
    {
        $this->installManualPay(['pay_should_fail' => true]);
        $invoice = $this->invoice($this->client(), 88.00);
        $service = app(PaymentService::class);

        $first = $service->processPayment($invoice, 'manual_pay', []);
        $second = $service->processPayment($invoice->fresh(), 'manual_pay', []);

        $this->assertFalse($first['success']);
        $this->assertFalse($second['success']);
        $this->assertTrue($second['reused'] ?? false);
        $this->assertSame(1, PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->where('status', 'failed')
            ->count());
    }

    public function test_payment_service_only_processes_unpaid_positive_invoices(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $paid = $this->invoice($client, 88.00);
        $paid->update(['status' => 'Paid']);
        $zero = $this->invoice($client, 0.00);

        $this->assertFalse(app(PaymentService::class)->processPayment($paid->fresh(), 'manual_pay', [])['success']);
        $this->assertFalse(app(PaymentService::class)->processPayment($zero, 'manual_pay', [])['success']);
    }

    public function test_cancelled_order_invoice_cannot_be_paid_or_callback_paid(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $order = $this->order($client, $invoice);
        app(OrderService::class)->cancel($order, '客户取消');

        $invoice->refresh();
        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame('Cancelled', $invoice->status);
        $this->assertFalse(app(PaymentService::class)->processPayment($invoice, 'manual_pay', [])['success']);
        $this->assertFalse(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'status' => 'paid',
            'trans_id' => 'CANCELLED-CALLBACK-1',
        ]));

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame('Cancelled', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'CANCELLED-CALLBACK-1']);
    }

    public function test_invoice_generation_rejects_negative_amount_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(InvoiceService::class)->generate($this->client(), [[
            'type' => 'product',
            'description' => '负数账单',
            'amount' => -1,
        ]]);
    }

    public function test_invoice_generation_rejects_zero_amount_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(InvoiceService::class)->generate($this->client(), [[
            'type' => 'product',
            'description' => '零元账单',
            'amount' => 0,
        ]]);
    }

    public function test_no_payment_invoice_allows_only_zero_amount_items(): void
    {
        $client = $this->client();
        $service = app(InvoiceService::class);

        $invoice = $service->generateNoPaymentRequired($client, [[
            'type' => 'downgrade',
            'description' => '无需付款降配调整',
            'amount' => 0,
            'rel_id' => 123,
        ]]);

        $this->assertSame('Paid', $invoice->status);
        $this->assertSame('no_payment_required', $invoice->payment_method);
        $this->assertSame('0.00', (string) $invoice->total);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'type' => 'downgrade',
            'amount' => 0,
            'rel_id' => 123,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $service->generateNoPaymentRequired($client, [[
            'type' => 'downgrade',
            'description' => '非法非零无需付款账单',
            'amount' => 1,
        ]]);
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

    public function test_payment_callback_completes_pending_attempts(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);

        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);
        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'pending',
        ]);

        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-ATTEMPT-001',
        ]));

        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'completed',
        ]);
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

    public function test_payment_callback_rejects_disabled_gateway(): void
    {
        $this->installManualPay();
        app(PluginManager::class)->disable('manual_pay');
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-DISABLED-001',
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'MANUAL-DISABLED-001']);
    }

    public function test_gateway_transaction_id_is_unique(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $first = $this->invoice($client, 88.00);
        $second = $this->invoice($client, 88.00);

        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $first->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-UNIQUE-001',
        ]));

        $this->assertFalse(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $second->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-UNIQUE-001',
        ]));
        $this->assertSame('Unpaid', $second->fresh()->status);
    }

    public function test_refund_only_marks_target_account_refunded(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $first = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 40,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-TARGET-1',
            'refunded' => 0,
        ]);
        $second = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 60,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-OTHER-1',
            'refunded' => 0,
        ]);

        $this->assertTrue(app(PaymentService::class)->refund($first, 40));

        $this->assertSame(1, $first->fresh()->refunded);
        $this->assertSame(0, $second->fresh()->refunded);
        $this->assertSame('Partially Refunded', $invoice->fresh()->status);
        $this->assertDatabaseHas('accounts', [
            'invoice_id' => $invoice->id,
            'type' => 'debit',
            'amount' => 40,
        ]);
    }

    public function test_repeated_partial_invoice_refund_is_rejected(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Paid']);

        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));
        $this->assertFalse(app(InvoiceService::class)->refund($invoice->fresh(), 40));

        $this->assertSame('Partially Refunded', $invoice->fresh()->status);
        $this->assertSame(1, Account::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'debit')
            ->count());
    }

    public function test_invoice_refund_updates_linked_order_status(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $order = $this->order($client, $invoice);
        $order->update(['status' => 'Paid']);

        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));
        $this->assertSame('Partially Refunded', $order->fresh()->status);

        $secondInvoice = $this->invoice($client, 100);
        $secondInvoice->update(['status' => 'Paid']);
        $secondOrder = $this->order($client, $secondInvoice);
        $secondOrder->update(['status' => 'Paid']);

        $this->assertTrue(app(InvoiceService::class)->refund($secondInvoice->fresh(), 100));
        $this->assertSame('Refunded', $secondOrder->fresh()->status);
    }

    public function test_payment_refund_does_not_mark_account_when_invoice_refund_fails(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Refunded']);
        $account = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-FAIL-1',
            'refunded' => 0,
        ]);

        $this->assertFalse(app(PaymentService::class)->refund($account, 100));
        $this->assertSame(0, $account->fresh()->refunded);
    }

    public function test_payment_refund_does_not_call_gateway_when_invoice_is_not_refundable(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Refunded']);
        $account = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-NO-GATEWAY-1',
            'refunded' => 0,
        ]);

        Plugin::query()->where('name', 'manual_pay')->update([
            'config' => ['refund_should_fail' => true],
        ]);

        $this->assertFalse(app(PaymentService::class)->refund($account, 100));
        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Refunded', $invoice->fresh()->status);
    }

    public function test_payment_refund_does_not_mark_local_refund_when_gateway_fails(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $account = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-GATEWAY-FAIL-1',
            'refunded' => 0,
        ]);

        Plugin::query()->where('name', 'manual_pay')->update([
            'config' => ['refund_should_fail' => true],
        ]);

        $this->assertFalse(app(PaymentService::class)->refund($account, 100));
        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_refund_requests', [
            'account_id' => $account->id,
            'status' => 'failed',
            'error' => '网关退款失败',
        ]);
        $this->assertDatabaseMissing('accounts', [
            'invoice_id' => $invoice->id,
            'type' => 'debit',
        ]);
    }

    public function test_failed_payment_refund_request_is_not_recreated_on_repeat_submit(): void
    {
        $this->installManualPay(['refund_should_fail' => true]);
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $account = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-REPEAT-FAILED-1',
            'refunded' => 0,
        ]);
        $service = app(PaymentService::class);

        $this->assertFalse($service->refund($account, 100));
        $this->assertFalse($service->refund($account->fresh(), 100));

        $this->assertSame(1, PaymentRefundRequest::query()
            ->where('account_id', $account->id)
            ->where('gateway_trans_id', 'REFUND-REPEAT-FAILED-1')
            ->where('amount', 100)
            ->count());
        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Paid', $invoice->fresh()->status);
    }

    public function test_payment_refund_records_successful_refund_request(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $account = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-REQUEST-SUCCESS-1',
            'refunded' => 0,
        ]);

        $this->assertTrue(app(PaymentService::class)->refund($account, 100));

        $this->assertSame(1, $account->fresh()->refunded);
        $this->assertDatabaseHas('payment_refund_requests', [
            'account_id' => $account->id,
            'status' => 'succeeded',
            'amount' => 100,
        ]);
    }

    public function test_payment_refund_records_gateway_success_when_local_refund_fails(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $account = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-LOCAL-FAIL-1',
            'refunded' => 0,
        ]);
        $service = new PaymentService(new class extends InvoiceService {
            public function canRefund(\App\Modules\Finance\Models\Invoice $invoice, float $amount): bool
            {
                return true;
            }

            public function refund(\App\Modules\Finance\Models\Invoice $invoice, float $amount): bool
            {
                return false;
            }
        });

        $this->assertFalse($service->refund($account, 100));

        $this->assertSame(0, $account->fresh()->refunded);
        $request = PaymentRefundRequest::query()->where('account_id', $account->id)->firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertSame('网关退款已成功，但本地退款落库失败', $request->error);
        $this->assertNotNull($request->gateway_refund_succeeded_at);
    }

    public function test_gateway_success_local_failure_refund_request_is_reused_for_local_recovery(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $account = Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-LOCAL-REPEAT-1',
            'refunded' => 0,
        ]);
        $service = new PaymentService(new class extends InvoiceService {
            public function canRefund(\App\Modules\Finance\Models\Invoice $invoice, float $amount): bool
            {
                return true;
            }

            public function refund(\App\Modules\Finance\Models\Invoice $invoice, float $amount): bool
            {
                return false;
            }
        });

        $this->assertFalse($service->refund($account, 100));
        $this->assertTrue(app(PaymentService::class)->refund($account->fresh(), 100));

        $this->assertSame(1, PaymentRefundRequest::query()
            ->where('account_id', $account->id)
            ->where('gateway_trans_id', 'REFUND-LOCAL-REPEAT-1')
            ->where('amount', 100)
            ->count());
        $this->assertSame(1, $account->fresh()->refunded);
        $this->assertSame('Refunded', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_refund_requests', [
            'account_id' => $account->id,
            'gateway_trans_id' => 'REFUND-LOCAL-REPEAT-1',
            'status' => 'succeeded',
        ]);
    }

    public function test_invoice_refund_requires_paid_status(): void
    {
        $invoice = $this->invoice($this->client(), 100);

        $this->assertFalse(app(InvoiceService::class)->refund($invoice, 10));
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_invoice_mark_paid_rejects_refunded_status(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Refunded']);

        $this->assertFalse(app(InvoiceService::class)->markAsPaid($invoice->fresh(), 'manual', 'REPAID-1'));
        $this->assertSame('Refunded', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'REPAID-1']);
    }

    public function test_order_mark_paid_does_not_mark_order_when_invoice_rejects_payment(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Refunded']);
        $order = $this->order($client, $invoice);

        $this->assertFalse(app(OrderService::class)->markAsPaid($order, 'manual', 'ORDER-REPAID-1'));

        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertSame('Refunded', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'ORDER-REPAID-1']);
    }

    public function test_order_mark_paid_does_not_mark_order_when_transaction_id_is_reused(): void
    {
        $client = $this->client();
        $firstInvoice = $this->invoice($client, 100);
        $secondInvoice = $this->invoice($client, 100);
        Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $firstInvoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual',
            'gateway_trans_id' => 'ORDER-DUPLICATE-1',
            'refunded' => 0,
        ]);
        $order = $this->order($client, $secondInvoice);

        $this->assertFalse(app(OrderService::class)->markAsPaid($order, 'manual', 'ORDER-DUPLICATE-1'));

        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertSame('Unpaid', $secondInvoice->fresh()->status);
    }

    public function test_order_mark_paid_rejects_cancelled_order_even_when_invoice_is_unpaid(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $order = $this->order($client, $invoice);
        $order->update(['status' => 'Cancelled']);

        $this->assertFalse(app(OrderService::class)->markAsPaid($order->fresh(), 'manual', 'ORDER-CANCELLED-PAID-1'));

        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'ORDER-CANCELLED-PAID-1']);
    }

    public function test_paid_order_cannot_be_cancelled(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $order = $this->order($client, $invoice);
        $order->update(['status' => 'Paid']);

        $this->assertFalse(app(OrderService::class)->cancel($order, '已支付订单'));
        $this->assertSame('Paid', $order->fresh()->status);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_non_super_admin_cannot_mark_invoice_paid_or_refund(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'finance-admin',
            'email' => 'finance-admin@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'finance-manager', 'guard_name' => 'web']);
        $admin->syncRoles(['finance-manager']);

        $invoice = $this->invoice($this->client(), 100);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.invoices.mark-paid', $invoice))
            ->assertForbidden();

        $invoice->update(['status' => 'Paid']);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.invoices.refund', $invoice), ['amount' => 10])
            ->assertForbidden();
    }

    private function installManualPay(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        Plugin::query()->where('name', 'manual_pay')->update([
            'config' => $config + [
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

    private function order(Client $client, Invoice $invoice): Order
    {
        return Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-PAY-' . random_int(1000, 9999),
            'status' => 'Pending',
            'amount' => $invoice->total,
            'currency_id' => $client->currency_id,
            'invoice_id' => $invoice->id,
        ]);
    }
}
