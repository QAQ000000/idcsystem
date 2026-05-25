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
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
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

    public function test_payment_attempt_masks_sensitive_gateway_result(): void
    {
        $invoice = $this->invoice($this->client(), 88.00);

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'gateway' => 'manual_pay',
            'amount' => $invoice->total,
            'status' => 'pending',
            'result' => [
                'success' => true,
                'pay_url' => 'https://pay.example.com/checkout',
                'access_token' => 'gateway-token',
                'signature' => 'gateway-signature',
                'nested' => [
                    'api_key' => 'gateway-key',
                    'message' => 'visible',
                ],
            ],
        ]);

        $attempt->refresh();
        $this->assertSame('[FILTERED]', $attempt->result['access_token']);
        $this->assertSame('[FILTERED]', $attempt->result['signature']);
        $this->assertSame('[FILTERED]', $attempt->result['nested']['api_key']);
        $this->assertSame('visible', $attempt->result['nested']['message']);
        $this->assertStringNotContainsString('gateway-token', json_encode($attempt->result));
        $this->assertStringNotContainsString('gateway-signature', json_encode($attempt->result));
        $this->assertStringNotContainsString('gateway-key', json_encode($attempt->result));
    }

    public function test_payment_refund_request_masks_sensitive_error_text(): void
    {
        $invoice = $this->invoice($this->client(), 88.00);
        $account = Account::query()->create([
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'type' => 'credit',
            'amount' => 88.00,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-MASK-001',
            'refunded' => 0,
        ]);

        $request = PaymentRefundRequest::query()->create([
            'account_id' => $account->id,
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-MASK-001',
            'amount' => 88.00,
            'status' => 'failed',
            'error' => 'gateway error password=plain-secret token:token-value signature=sign-value',
        ]);

        $request->refresh();
        $this->assertStringContainsString('password=[FILTERED]', $request->error);
        $this->assertStringContainsString('token:[FILTERED]', $request->error);
        $this->assertStringContainsString('signature=[FILTERED]', $request->error);
        $this->assertStringNotContainsString('plain-secret', $request->error);
        $this->assertStringNotContainsString('token-value', $request->error);
        $this->assertStringNotContainsString('sign-value', $request->error);
    }

    public function test_payment_service_reuses_pending_payment_attempt_for_same_invoice_gateway(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);
        $service = app(PaymentService::class);

        $first = $service->processPayment($invoice, 'manual_pay', []);
        $second = $service->processPayment($invoice->fresh(), 'manual_pay', []);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success'], json_encode($second, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($second['reused'] ?? false);
        $this->assertSame(1, PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->where('status', 'pending')
            ->count());
    }

    public function test_payment_service_expires_pending_attempt_when_invoice_amount_changes(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);
        $service = app(PaymentService::class);

        $first = $service->processPayment($invoice, 'manual_pay', []);
        $this->assertTrue($first['success']);

        $invoice->update([
            'subtotal' => 99.00,
            'tax' => 0,
            'total' => 99.00,
        ]);

        $second = $service->processPayment($invoice->fresh(), 'manual_pay', []);

        $this->assertTrue($second['success'], json_encode($second, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($second['reused'] ?? false);
        $this->assertSame(99.00, $second['amount']);
        $this->assertSame(1, PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->where('amount', 88.00)
            ->where('status', 'expired')
            ->count());
        $this->assertSame(1, PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->where('amount', 99.00)
            ->where('status', 'pending')
            ->count());
    }

    public function test_payment_service_expires_failed_attempt_and_allows_retry_after_gateway_config_is_fixed(): void
    {
        $this->installManualPay(['pay_should_fail' => true]);
        $invoice = $this->invoice($this->client(), 88.00);
        $service = app(PaymentService::class);

        $first = $service->processPayment($invoice, 'manual_pay', []);
        $this->assertFalse($first['success']);

        Plugin::query()->where('name', 'manual_pay')->update([
            'config' => [
                'instructions' => '请转账到测试账户。',
                'bank_name' => '测试银行',
            ],
        ]);
        Facade::clearResolvedInstance('plugin.manager');
        $this->app->forgetInstance('plugin.manager');
        $second = app(PaymentService::class)->processPayment($invoice->fresh(), 'manual_pay', []);

        $this->assertTrue($second['success'], json_encode($second, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($second['reused'] ?? false);
        $this->assertSame(1, PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->where('status', 'expired')
            ->count());
        $this->assertSame(1, PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->where('status', 'pending')
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

    public function test_client_invoice_show_only_displays_payment_form_for_unpaid_invoices(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $unpaid = $this->invoice($client, 88.00);
        $refunded = $this->invoice($client, 88.00);
        $refunded->update(['status' => 'Refunded']);

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.show', $unpaid))
            ->assertOk()
            ->assertSee('发起支付');

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.show', $refunded))
            ->assertOk()
            ->assertDontSee('发起支付')
            ->assertDontSee('payment_method');
    }

    public function test_inactive_or_deleted_client_invoice_cannot_be_paid(): void
    {
        $this->installManualPay();
        $inactive = $this->client('pay-inactive', 'pay-inactive@example.com');
        $inactiveInvoice = $this->invoice($inactive, 100);
        $inactive->update(['status' => 2]);
        $deleted = $this->client('pay-deleted', 'pay-deleted@example.com');
        $deletedInvoice = $this->invoice($deleted, 100);
        $deleted->delete();

        $this->assertSame(
            'Client account is not payable',
            app(PaymentService::class)->processPayment($inactiveInvoice->fresh(), 'manual_pay', [])['message']
        );
        $this->assertFalse(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $inactiveInvoice->id,
            'amount' => 100,
            'status' => 'paid',
            'trans_id' => 'INACTIVE-CALLBACK-1',
        ]));
        $this->assertFalse(app(InvoiceService::class)->markAsPaid($deletedInvoice->fresh(), 'manual', 'DELETED-MARK-PAID-1'));

        $this->assertSame('Unpaid', $inactiveInvoice->fresh()->status);
        $this->assertSame('Unpaid', $deletedInvoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'INACTIVE-CALLBACK-1']);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'DELETED-MARK-PAID-1']);
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

    public function test_payment_service_rechecks_order_status_inside_transaction(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $order = $this->order($client, $invoice);
        $invoice->load('order');

        $order->update(['status' => 'Cancelled']);

        $result = app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $this->assertFalse($result['success']);
        $this->assertSame('Order is not payable', $result['message']);
        $this->assertSame('Cancelled', $order->fresh()->status);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'pending',
        ]);
    }

    public function test_payment_service_rechecks_latest_invoice_amount_inside_transaction(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 100);
        $staleInvoice = $invoice->fresh();
        $invoice->update([
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
        ]);

        $result = app(PaymentService::class)->processPayment($staleInvoice, 'manual_pay', []);

        $this->assertFalse($result['success']);
        $this->assertSame('Invoice amount must be greater than zero', $result['message']);
        $this->assertDatabaseMissing('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'pending',
        ]);
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
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

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

    public function test_payment_callback_route_marks_invoice_paid(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $this->post(route('payment.callback', 'manual_pay'), [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-HTTP-001',
        ])->assertOk()
            ->assertSee('ok');

        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('accounts', ['invoice_id' => $invoice->id, 'gateway_trans_id' => 'MANUAL-HTTP-001']);
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
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 77.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-002',
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_payment_callback_rejects_without_pending_payment_attempt(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => 'MANUAL-NO-ATTEMPT-001',
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'MANUAL-NO-ATTEMPT-001']);
    }

    public function test_payment_callback_rejects_array_payload_fields(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 1.00);
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => [$invoice->id],
            'amount' => [1.00],
            'status' => 'paid',
            'trans_id' => ['MANUAL-ARRAY-001'],
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'MANUAL-ARRAY-001']);
        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'pending',
        ]);
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
        app(PaymentService::class)->processPayment($first, 'manual_pay', []);
        app(PaymentService::class)->processPayment($second, 'manual_pay', []);

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

    public function test_payment_refund_allows_multiple_partial_refunds_for_same_account_until_fully_refunded(): void
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
            'gateway_trans_id' => 'REFUND-PARTIAL-SAME-1',
            'refunded' => 0,
        ]);
        $service = app(PaymentService::class);

        $this->assertTrue($service->refund($account, 40));
        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Partially Refunded', $invoice->fresh()->status);

        $this->assertTrue($service->refund($account->fresh(), 40));
        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Partially Refunded', $invoice->fresh()->status);

        $this->assertTrue($service->refund($account->fresh(), 20));
        $this->assertSame(1, $account->fresh()->refunded);
        $this->assertSame('Refunded', $invoice->fresh()->status);

        $this->assertFalse($service->refund($account->fresh(), 1));
        $this->assertSame('Refunded', $invoice->fresh()->status);
        $this->assertSame(3, PaymentRefundRequest::query()
            ->where('account_id', $account->id)
            ->where('status', 'succeeded')
            ->count());
    }

    public function test_payment_refund_rejects_non_payment_account(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Paid']);
        $refundAccount = Account::query()->create([
            'client_id' => $invoice->client_id,
            'invoice_id' => $invoice->id,
            'type' => 'debit',
            'amount' => 100,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'REFUND-DEBIT-ACCOUNT-1',
            'refunded' => 0,
        ]);

        $this->assertFalse(app(PaymentService::class)->refund($refundAccount, 100));

        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertSame(0, $refundAccount->fresh()->refunded);
        $this->assertDatabaseMissing('payment_refund_requests', [
            'account_id' => $refundAccount->id,
            'gateway_trans_id' => 'REFUND-DEBIT-ACCOUNT-1',
        ]);
        $this->assertDatabaseMissing('accounts', [
            'invoice_id' => $invoice->id,
            'type' => 'debit',
            'description' => 'Invoice refund ' . $invoice->invoice_number,
        ]);
    }

    public function test_multiple_partial_invoice_refunds_are_limited_by_remaining_amount(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Paid']);

        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));
        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));
        $this->assertFalse(app(InvoiceService::class)->refund($invoice->fresh(), 30));

        $this->assertSame('Partially Refunded', $invoice->fresh()->status);
        $this->assertSame(2, Account::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'debit')
            ->count());
        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 20));
        $this->assertSame('Refunded', $invoice->fresh()->status);
        $this->assertFalse(app(InvoiceService::class)->refund($invoice->fresh(), 1));
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

    public function test_partial_refund_logs_host_review_without_changing_service_state(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $order = $this->order($client, $invoice);
        $order->update(['status' => 'Paid']);
        $host = $this->host($client, $order, ['status' => 'Active']);

        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));

        $this->assertSame('Active', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'refund_partial',
            'message' => '订单账单已部分退款，请人工确认服务是否需要调整',
        ]);
    }

    public function test_full_refund_terminates_active_or_suspended_order_hosts(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $order = $this->order($client, $invoice);
        $order->update(['status' => 'Paid']);
        $active = $this->host($client, $order, ['status' => 'Active']);
        $suspended = $this->host($client, $order, ['status' => 'Suspended']);
        $pending = $this->host($client, $order, ['status' => 'Pending']);

        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 100));

        $this->assertSame('Terminated', $active->fresh()->status);
        $this->assertSame('Terminated', $suspended->fresh()->status);
        $this->assertSame('Pending', $pending->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $active->id, 'action' => 'terminate']);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $suspended->id, 'action' => 'terminate']);
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

    public function test_payment_refund_fails_when_gateway_plugin_is_unavailable(): void
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
            'gateway_trans_id' => 'REFUND-GATEWAY-MISSING-1',
            'refunded' => 0,
        ]);
        Plugin::query()->where('name', 'manual_pay')->update(['status' => 0]);

        $this->assertFalse(app(PaymentService::class)->refund($account, 100));

        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_refund_requests', [
            'account_id' => $account->id,
            'status' => 'failed',
            'error' => '支付网关不可用',
        ]);
        $this->assertDatabaseMissing('accounts', [
            'invoice_id' => $invoice->id,
            'type' => 'debit',
        ]);
    }

    public function test_failed_payment_refund_request_allows_retry_after_gateway_config_is_fixed(): void
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
        $this->assertSame(1, PaymentRefundRequest::query()
            ->where('account_id', $account->id)
            ->where('status', 'failed')
            ->count());
        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Paid', $invoice->fresh()->status);

        Plugin::query()->where('name', 'manual_pay')->update(['config' => []]);

        $this->assertTrue($service->refund($account->fresh(), 100));

        $this->assertSame(1, PaymentRefundRequest::query()
            ->where('account_id', $account->id)
            ->where('gateway_trans_id', 'REFUND-REPEAT-FAILED-1')
            ->where('amount', 100)
            ->count());
        $this->assertSame(1, $account->fresh()->refunded);
        $this->assertSame('Refunded', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_refund_requests', [
            'account_id' => $account->id,
            'status' => 'succeeded',
            'amount' => 100,
            'error' => null,
        ]);
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

    public function test_failed_gateway_refund_retry_rechecks_local_refundability_before_gateway_call(): void
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
            'gateway_trans_id' => 'REFUND-RECHECK-1',
            'refunded' => 0,
        ]);

        $this->assertFalse(app(PaymentService::class)->refund($account, 100));
        $invoice->update(['status' => 'Cancelled']);
        Plugin::query()->where('name', 'manual_pay')->update(['config' => []]);

        $this->assertFalse(app(PaymentService::class)->refund($account->fresh(), 100));

        $this->assertSame(0, $account->fresh()->refunded);
        $this->assertSame('Cancelled', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_refund_requests', [
            'account_id' => $account->id,
            'gateway_trans_id' => 'REFUND-RECHECK-1',
            'status' => 'failed',
            'error' => '网关退款失败',
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

    public function test_admin_order_show_hides_actions_without_permission_or_invalid_status(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $order = $this->order($client, $invoice);
        $admin = AdminUser::query()->create([
            'username' => 'order-view-only',
            'email' => 'order-view-only@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'order-viewer', 'guard_name' => 'web']);
        $admin->syncRoles(['order-viewer']);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'order.view', 'guard_name' => 'web']));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('审核并标记支付')
            ->assertDontSee('取消订单')
            ->assertSee('当前没有可执行的订单操作');

        $order->update(['status' => 'Paid']);
        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.orders.show', $order->fresh()))
            ->assertOk()
            ->assertDontSee('审核并标记支付')
            ->assertDontSee('取消订单')
            ->assertSee('当前没有可执行的订单操作');
    }

    public function test_admin_order_show_hides_approve_for_deleted_client(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $order = $this->order($client, $invoice);
        $client->delete();

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('已删除')
            ->assertSee('客户已删除，不能审核并标记支付该订单。')
            ->assertDontSee('value="ADMIN-' . $order->id . '"', false)
            ->assertSee('取消订单');
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

    public function test_admin_invoice_show_hides_actions_without_permission_or_invalid_status(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $admin = AdminUser::query()->create([
            'username' => 'invoice-view-only',
            'email' => 'invoice-view-only@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'invoice-viewer', 'guard_name' => 'web']);
        $admin->syncRoles(['invoice-viewer']);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'invoice.view', 'guard_name' => 'web']));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('标记已支付')
            ->assertDontSee('记录退款')
            ->assertSee('当前没有可执行的账单操作');

        $invoice->update(['status' => 'Cancelled']);
        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.invoices.show', $invoice->fresh()))
            ->assertOk()
            ->assertDontSee('标记已支付')
            ->assertDontSee('记录退款')
            ->assertSee('当前没有可执行的账单操作');
    }

    public function test_admin_invoice_show_defaults_refund_amount_to_remaining_refundable_amount(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Paid']);
        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.invoices.show', $invoice->fresh()))
            ->assertOk()
            ->assertSee('剩余可退：60.00')
            ->assertSee('max="60.00"', false)
            ->assertSee('value="60.00"', false)
            ->assertDontSee('value="100"', false);
    }

    public function test_admin_invoice_show_does_not_show_empty_action_message_when_partial_refund_can_continue(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Paid']);
        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.invoices.show', $invoice->fresh()))
            ->assertOk()
            ->assertSee('记录退款')
            ->assertDontSee('当前没有可执行的账单操作');
    }

    public function test_admin_invoice_show_hides_mark_paid_for_deleted_client(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $client->delete();

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('已删除')
            ->assertSee('客户已删除，不能标记支付该账单。')
            ->assertDontSee('value="ADMIN-' . $invoice->id . '"', false)
            ->assertSee('当前没有可执行的账单操作');
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

    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'super-finance-' . random_int(1000, 9999),
            'email' => 'super-finance-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(string $username = 'pay-client', string $email = 'pay-client@example.com'): Client
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => $username,
            'email' => $email,
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

    private function host(Client $client, Order $order, array $overrides = []): Host
    {
        $group = \App\Modules\Product\Models\ProductGroup::query()->firstOrCreate(['name' => '支付退款服务产品']);
        $product = \App\Modules\Product\Models\Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Refund Host Product ' . random_int(1000, 9999),
            'type' => 'vps',
        ]);

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 100,
            'recurring_amount' => 100,
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides));
    }
}
