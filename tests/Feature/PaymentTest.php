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
use App\Plugins\Contracts\PaymentGatewayInterface;
use App\Plugins\Core\PluginManager;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Plugins\Gateway\EpayAlipay\src\EpayClient;
use Plugins\Gateway\AlipaySdk\src\AlipayClient;
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

    public function test_epay_gateway_plugins_are_independent_channels(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('gateway'));

        foreach (['epay_alipay', 'epay_wxpay', 'epay_qqpay', 'epay_bank'] as $name) {
            $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === $name), $name . ' should be scannable');
            $this->assertTrue($manager->install('gateway', $name), $name . ' should install');
            $this->assertTrue($manager->enable($name), $name . ' should enable');
            $this->assertDatabaseHas('plugins', ['name' => $name, 'type' => 'gateway', 'status' => 1]);
        }
    }

    public function test_alipay_sdk_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('gateway'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'alipay_sdk'));
        $this->assertTrue($manager->install('gateway', 'alipay_sdk'));
        $this->assertDatabaseHas('plugins', ['name' => 'alipay_sdk', 'type' => 'gateway', 'status' => 0]);
        $this->assertTrue($manager->enable('alipay_sdk'));
        $this->assertDatabaseHas('plugins', ['name' => 'alipay_sdk', 'status' => 1]);
    }

    public function test_alipay_sdk_plugin_pay_returns_signed_redirect_url(): void
    {
        $this->installAlipaySdk();
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->processPayment($invoice, 'alipay_sdk', [
            'return_url' => route('client.invoices.show', $invoice),
        ]);

        $this->assertTrue($result['success'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame('redirect', $result['pay_type']);
        $this->assertStringStartsWith('https://openapi-sandbox.dl.alipaydev.com/gateway.do?', $result['payment_url']);
        $this->assertStringContainsString('method=alipay.trade.page.pay', $result['payment_url']);
        $this->assertStringContainsString('app_id=2021000000000000', $result['payment_url']);
        $this->assertStringContainsString('sign=', $result['payment_url']);
    }

    public function test_alipay_sdk_plugin_pay_fails_when_config_missing(): void
    {
        app(PluginManager::class)->install('gateway', 'alipay_sdk');
        app(PluginManager::class)->enable('alipay_sdk');
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->processPayment($invoice, 'alipay_sdk', []);

        $this->assertFalse($result['success']);
        $this->assertSame('支付宝官方未配置', $result['message']);
    }

    public function test_alipay_sdk_notify_verifies_rsa2_signature(): void
    {
        [$privateKey, $publicKey] = $this->alipayKeyPair();
        $this->installAlipaySdk([
            'private_key' => $privateKey,
            'alipay_public_key' => $publicKey,
        ]);

        $payload = $this->signedAlipayPayload($privateKey);

        $this->assertTrue(plugin('gateway')->get('alipay_sdk')->notify($payload));
        $payload['total_amount'] = '188.00';
        $this->assertFalse(plugin('gateway')->get('alipay_sdk')->notify($payload));
    }

    public function test_alipay_sdk_callback_marks_invoice_paid(): void
    {
        [$privateKey, $publicKey] = $this->alipayKeyPair();
        $this->installAlipaySdk([
            'private_key' => $privateKey,
            'alipay_public_key' => $publicKey,
        ]);
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'alipay_sdk', []);

        $payload = $this->signedAlipayPayload($privateKey, [
            'out_trade_no' => (string) $invoice->id,
            'total_amount' => '88.00',
            'trade_no' => 'ALIPAY-CALLBACK-001',
        ]);

        $this->assertTrue(app(PaymentService::class)->handleCallback('alipay_sdk', [
            'invoice_id' => $payload['out_trade_no'],
            'trans_id' => $payload['trade_no'],
            'amount' => $payload['total_amount'],
            'status' => strtolower($payload['trade_status']),
        ] + $payload));

        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('accounts', [
            'invoice_id' => $invoice->id,
            'payment_method' => 'alipay_sdk',
            'gateway_trans_id' => 'ALIPAY-CALLBACK-001',
        ]);
    }

    public function test_wechat_pay_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('gateway'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'wechat_pay'));
        $this->assertTrue($manager->install('gateway', 'wechat_pay'));
        $this->assertDatabaseHas('plugins', ['name' => 'wechat_pay', 'type' => 'gateway', 'status' => 0]);
        $this->assertTrue($manager->enable('wechat_pay'));
        $this->assertDatabaseHas('plugins', ['name' => 'wechat_pay', 'status' => 1]);
    }

    public function test_wechat_pay_plugin_pay_returns_h5_redirect_url(): void
    {
        $this->installWechatPay();
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->processPayment($invoice, 'wechat_pay', []);

        $this->assertTrue($result['success'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame('redirect', $result['pay_type']);
        $this->assertStringStartsWith('https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id=', $result['payment_url']);
        $this->assertSame('wechat_pay', $result['gateway']);
    }

    public function test_wechat_pay_plugin_pay_fails_when_config_missing(): void
    {
        app(PluginManager::class)->install('gateway', 'wechat_pay');
        app(PluginManager::class)->enable('wechat_pay');
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->processPayment($invoice, 'wechat_pay', []);

        $this->assertFalse($result['success']);
        $this->assertSame('微信支付官方未配置', $result['message']);
    }

    public function test_wechat_pay_notify_verifies_rsa_signature(): void
    {
        [$privateKey, $publicKey] = $this->alipayKeyPair();
        $this->installWechatPay([
            'private_key' => $privateKey,
            'wechatpay_public_key' => $publicKey,
        ]);

        $payload = $this->signedWechatPayPayload($privateKey, $publicKey);

        $this->assertTrue(plugin('gateway')->get('wechat_pay')->notify($payload));
        $payload['wechatpay_body'] = str_replace('WECHAT-TRADE-001', 'WECHAT-TRADE-TAMPERED', $payload['wechatpay_body']);
        $this->assertFalse(plugin('gateway')->get('wechat_pay')->notify($payload));
    }

    public function test_wechat_pay_callback_marks_invoice_paid(): void
    {
        [$privateKey, $publicKey] = $this->alipayKeyPair();
        $this->installWechatPay([
            'private_key' => $privateKey,
            'wechatpay_public_key' => $publicKey,
        ]);
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'wechat_pay', []);

        $payload = $this->signedWechatPayPayload($privateKey, $publicKey, [
            'out_trade_no' => (string) $invoice->id,
            'transaction_id' => 'WECHAT-CALLBACK-001',
            'amount' => ['total' => 8800, 'currency' => 'CNY'],
        ]);

        $normalized = plugin('gateway')->get('wechat_pay')->normalizedCallback($payload);

        $this->assertTrue(app(PaymentService::class)->handleCallback('wechat_pay', $normalized));
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('accounts', [
            'invoice_id' => $invoice->id,
            'payment_method' => 'wechat_pay',
            'gateway_trans_id' => 'WECHAT-CALLBACK-001',
        ]);
    }

    public function test_epay_client_sign_matches_reference_vector(): void
    {
        $client = new EpayClient('https://pay.example.com/', '1001', 'secret');

        $this->assertSame(
            md5('money=0.01&name=账单 #INV001&notify_url=https://idc.example.com/notify&out_trade_no=123&pid=1001&type=alipaysecret'),
            $client->referenceSign([
                'pid' => '1001',
                'type' => 'alipay',
                'out_trade_no' => '123',
                'notify_url' => 'https://idc.example.com/notify',
                'name' => '账单 #INV001',
                'money' => '0.01',
                'sign_type' => 'MD5',
                'sign' => 'ignore',
            ])
        );
    }

    public function test_epay_plugin_pay_returns_redirect_url_with_valid_config(): void
    {
        $this->installEpayAlipay();
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->processPayment($invoice, 'epay_alipay', [
            'return_url' => route('client.invoices.show', $invoice),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('redirect', $result['pay_type']);
        $this->assertStringStartsWith('https://pay.example.com/submit.php?', $result['payment_url']);
        $this->assertStringContainsString('type=alipay', $result['payment_url']);
        $this->assertStringContainsString('money=88.00', $result['payment_url']);
        $this->assertStringContainsString('sign=', $result['payment_url']);
    }

    public function test_epay_plugin_pay_fails_when_config_missing(): void
    {
        app(PluginManager::class)->install('gateway', 'epay_alipay');
        app(PluginManager::class)->enable('epay_alipay');
        $invoice = $this->invoice($this->client(), 88.00);

        $result = app(PaymentService::class)->processPayment($invoice, 'epay_alipay', []);

        $this->assertFalse($result['success']);
        $this->assertSame('易支付 - 支付宝未配置', $result['message']);
    }

    public function test_epay_notify_verifies_correct_signature(): void
    {
        $this->installEpayAlipay();

        $this->assertTrue(plugin('gateway')->get('epay_alipay')->notify($this->signedEpayPayload()));
    }

    public function test_epay_notify_rejects_tampered_signature(): void
    {
        $this->installEpayAlipay();
        $payload = $this->signedEpayPayload(['money' => '88.00']);
        $payload['money'] = '99.00';

        $this->assertFalse(plugin('gateway')->get('epay_alipay')->notify($payload));
    }

    public function test_epay_callback_marks_invoice_paid_on_valid_notify(): void
    {
        $this->installEpayAlipay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'epay_alipay', []);

        $payload = $this->signedEpayPayload([
            'out_trade_no' => (string) $invoice->id,
            'money' => '88.00',
            'trade_no' => 'EPAY-VALID-001',
        ]);

        $this->assertTrue(app(PaymentService::class)->handleCallback('epay_alipay', $payload));
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('accounts', [
            'invoice_id' => $invoice->id,
            'payment_method' => 'epay_alipay',
            'gateway_trans_id' => 'EPAY-VALID-001',
        ]);
    }

    public function test_epay_callback_rejects_underpayment(): void
    {
        $this->installEpayAlipay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'epay_alipay', []);

        $payload = $this->signedEpayPayload([
            'out_trade_no' => (string) $invoice->id,
            'money' => '87.99',
            'trade_no' => 'EPAY-UNDERPAID',
        ]);

        $this->assertFalse(app(PaymentService::class)->handleCallback('epay_alipay', $payload));
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'EPAY-UNDERPAID']);
    }

    public function test_epay_callback_credits_overpayment_to_client_balance(): void
    {
        $this->installEpayAlipay();
        $client = $this->client();
        $invoice = $this->invoice($client, 88.00);
        app(PaymentService::class)->processPayment($invoice, 'epay_alipay', []);

        $payload = $this->signedEpayPayload([
            'out_trade_no' => (string) $invoice->id,
            'money' => '99.00',
            'trade_no' => 'EPAY-OVERPAID',
        ]);

        $this->assertTrue(app(PaymentService::class)->handleCallback('epay_alipay', $payload));
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertSame('11.00', (string) $client->fresh()->credit);
        $this->assertDatabaseHas('accounts', [
            'invoice_id' => $invoice->id,
            'gateway_trans_id' => 'EPAY-OVERPAID',
            'amount' => '88.00',
        ]);
        $this->assertDatabaseHas('credits', [
            'client_id' => $client->id,
            'type' => 'add',
            'amount' => '11.00',
            'description' => '支付超额自动转余额：账单 ' . $invoice->invoice_number,
        ]);
    }

    public function test_epay_callback_rejects_overpayment_larger_than_credit_capacity(): void
    {
        $this->installEpayAlipay();
        $client = $this->client();
        $invoice = $this->invoice($client, 88.00);
        app(PaymentService::class)->processPayment($invoice, 'epay_alipay', []);

        $payload = $this->signedEpayPayload([
            'out_trade_no' => (string) $invoice->id,
            'money' => '100000088.00',
            'trade_no' => 'EPAY-OVERPAID-TOO-LARGE',
        ]);

        $this->assertFalse(app(PaymentService::class)->handleCallback('epay_alipay', $payload));
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $client->fresh()->credit);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'EPAY-OVERPAID-TOO-LARGE']);
        $this->assertDatabaseCount('credits', 0);
    }

    public function test_epay_callback_rejects_duplicate_notify(): void
    {
        $this->installEpayAlipay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'epay_alipay', []);

        $payload = $this->signedEpayPayload([
            'out_trade_no' => (string) $invoice->id,
            'money' => '88.00',
            'trade_no' => 'EPAY-DUPLICATE-001',
        ]);

        $this->assertTrue(app(PaymentService::class)->handleCallback('epay_alipay', $payload));
        $this->assertFalse(app(PaymentService::class)->handleCallback('epay_alipay', $payload));
        $this->assertSame(1, Account::query()->where('gateway_trans_id', 'EPAY-DUPLICATE-001')->count());
    }

    public function test_epay_plugin_routes_can_handle_valid_notify_after_plugin_is_enabled(): void
    {
        $this->installEpayAlipay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'epay_alipay', []);
        $this->bootPluginRoutesForTest();

        $payload = $this->signedEpayPayload([
            'out_trade_no' => (string) $invoice->id,
            'money' => '88.00',
            'trade_no' => 'EPAY-ROUTE-001',
        ]);

        $this->get('/plugin/epay_alipay/notify?' . http_build_query($payload))
            ->assertOk()
            ->assertSee('success');
        $this->assertSame('Paid', $invoice->fresh()->status);
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
                'authorization' => 'gateway-auth',
                'cookie' => 'gateway-cookie',
                'session_id' => 'gateway-session',
                'bearer_token' => 'gateway-bearer',
                'signature' => 'gateway-signature',
                'nested' => [
                    'api_key' => 'gateway-key',
                    'message' => 'visible',
                ],
            ],
        ]);

        $attempt->refresh();
        $this->assertSame('[FILTERED]', $attempt->result['access_token']);
        $this->assertSame('[FILTERED]', $attempt->result['authorization']);
        $this->assertSame('[FILTERED]', $attempt->result['cookie']);
        $this->assertSame('[FILTERED]', $attempt->result['session_id']);
        $this->assertSame('[FILTERED]', $attempt->result['bearer_token']);
        $this->assertSame('[FILTERED]', $attempt->result['signature']);
        $this->assertSame('[FILTERED]', $attempt->result['nested']['api_key']);
        $this->assertSame('visible', $attempt->result['nested']['message']);
        $this->assertStringNotContainsString('gateway-token', json_encode($attempt->result));
        $this->assertStringNotContainsString('gateway-auth', json_encode($attempt->result));
        $this->assertStringNotContainsString('gateway-cookie', json_encode($attempt->result));
        $this->assertStringNotContainsString('gateway-session', json_encode($attempt->result));
        $this->assertStringNotContainsString('gateway-bearer', json_encode($attempt->result));
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
            'error' => 'gateway error password=plain-secret token:token-value authorization=auth-value cookie:cookie-value session=session-value bearer=bearer-value signature=sign-value',
        ]);

        $request->refresh();
        $this->assertStringContainsString('password=[FILTERED]', $request->error);
        $this->assertStringContainsString('token:[FILTERED]', $request->error);
        $this->assertStringContainsString('authorization=[FILTERED]', $request->error);
        $this->assertStringContainsString('cookie:[FILTERED]', $request->error);
        $this->assertStringContainsString('session=[FILTERED]', $request->error);
        $this->assertStringContainsString('bearer=[FILTERED]', $request->error);
        $this->assertStringContainsString('signature=[FILTERED]', $request->error);
        $this->assertStringNotContainsString('plain-secret', $request->error);
        $this->assertStringNotContainsString('token-value', $request->error);
        $this->assertStringNotContainsString('auth-value', $request->error);
        $this->assertStringNotContainsString('cookie-value', $request->error);
        $this->assertStringNotContainsString('session-value', $request->error);
        $this->assertStringNotContainsString('bearer-value', $request->error);
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

    public function test_client_can_pay_unpaid_invoice_with_credit_balance(): void
    {
        $client = $this->client();
        $client->update(['credit' => 120]);
        $invoice = $this->invoice($client, 88.00);

        $this->actingAs($client, 'client')
            ->post(route('client.invoices.pay-with-credit', $invoice))
            ->assertRedirect(route('client.invoices.show', $invoice))
            ->assertSessionHas('status', '余额支付成功');

        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertSame('credit', $invoice->fresh()->payment_method);
        $this->assertSame('32.00', (string) $client->fresh()->credit);
        $this->assertDatabaseHas('credits', [
            'client_id' => $client->id,
            'type' => 'deduct',
            'amount' => 88.00,
            'balance' => 32.00,
            'description' => '余额支付：账单 ' . $invoice->invoice_number,
        ]);
        $this->assertDatabaseHas('accounts', [
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 88.00,
            'payment_method' => 'credit',
        ]);
    }

    public function test_credit_payment_rejects_insufficient_balance_without_deducting_credit(): void
    {
        $client = $this->client();
        $client->update(['credit' => 50]);
        $invoice = $this->invoice($client, 88.00);

        $this->actingAs($client, 'client')
            ->post(route('client.invoices.pay-with-credit', $invoice))
            ->assertRedirect(route('client.invoices.show', $invoice))
            ->assertSessionHas('error', '账户余额不足，无法支付该账单');

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame('50.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
        $this->assertDatabaseMissing('accounts', [
            'invoice_id' => $invoice->id,
            'payment_method' => 'credit',
        ]);
    }

    public function test_client_can_create_recharge_invoice_from_account_page(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->get(route('client.account.recharge'))
            ->assertOk()
            ->assertSee('账户充值')
            ->assertSee('当前余额');

        $response = $this->actingAs($client, 'client')
            ->post(route('client.account.recharge.store'), ['amount' => '123.45']);

        $invoice = Invoice::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $response
            ->assertRedirect(route('client.invoices.show', $invoice))
            ->assertSessionHas('status', '充值账单已生成，请完成支付');

        $this->assertSame('Unpaid', $invoice->status);
        $this->assertSame('123.45', (string) $invoice->total);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'type' => 'recharge',
            'description' => '账户充值',
            'amount' => 123.45,
        ]);
    }

    public function test_paid_recharge_invoice_adds_credit_once(): void
    {
        $client = $this->client();
        $invoice = app(InvoiceService::class)->generateRecharge($client, 88.00);

        $this->assertTrue(app(InvoiceService::class)->markAsPaid($invoice, 'manual', 'RECHARGE-PAID-1'));
        $this->assertSame('88.00', (string) $client->fresh()->credit);
        $this->assertDatabaseHas('credits', [
            'client_id' => $client->id,
            'type' => 'add',
            'amount' => 88.00,
            'balance' => 88.00,
            'description' => '充值：账单 ' . $invoice->invoice_number,
        ]);

        app(\App\Modules\Order\Services\HostService::class)->applyPaidInvoice($invoice->fresh());

        $this->assertSame('88.00', (string) $client->fresh()->credit);
        $this->assertSame(1, \App\Modules\Finance\Models\Credit::query()
            ->where('client_id', $client->id)
            ->where('description', '充值：账单 ' . $invoice->invoice_number)
            ->count());
    }

    public function test_client_cannot_pay_other_clients_invoice_with_credit(): void
    {
        $owner = $this->client('credit-owner', 'credit-owner@example.com');
        $other = $this->client('credit-other', 'credit-other@example.com');
        $other->update(['credit' => 200]);
        $invoice = $this->invoice($owner, 88.00);

        $this->actingAs($other, 'client')
            ->post(route('client.invoices.pay-with-credit', $invoice))
            ->assertForbidden();

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame('200.00', (string) $other->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
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

    public function test_client_invoice_show_displays_credit_payment_state(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $client->update(['credit' => 120]);
        $payable = $this->invoice($client, 88.00);
        $insufficient = $this->invoice($client, 150.00);

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.show', $payable))
            ->assertOk()
            ->assertSee('当前余额：120.00')
            ->assertSee('使用余额支付');

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.show', $insufficient))
            ->assertOk()
            ->assertSee('余额不足，暂不能使用余额支付该账单。')
            ->assertDontSee(route('client.invoices.pay-with-credit', $insufficient));
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

    public function test_order_cancel_expires_pending_payment_attempts(): void
    {
        $this->installManualPay();
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $order = $this->order($client, $invoice);
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'pending',
        ]);

        $this->assertTrue(app(OrderService::class)->cancel($order, '客户取消'));

        $attempt = PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', 'manual_pay')
            ->firstOrFail();
        $this->assertSame('expired', $attempt->status);
        $this->assertSame('Order cancelled', $attempt->result['expired_reason']);
        $this->assertSame('客户取消', $attempt->result['cancel_reason']);
        $this->assertTrue(app(PluginManager::class)->disable('manual_pay'));
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

    public function test_invoice_generation_rejects_amount_above_database_capacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('账单金额超出允许范围');

        app(InvoiceService::class)->generate($this->client(), [[
            'type' => 'product',
            'description' => '超大账单',
            'amount' => 100000000,
        ]]);
    }

    public function test_invoice_generation_rejects_inactive_or_deleted_clients(): void
    {
        $inactive = $this->client('invoice-inactive', 'invoice-inactive@example.com');
        $inactive->update(['status' => 2]);

        try {
            app(InvoiceService::class)->generate($inactive->fresh(), [[
                'type' => 'product',
                'description' => '停用客户账单',
                'amount' => 100,
            ]]);
            $this->fail('Expected invoice generation to reject inactive clients.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('客户账号状态不允许生成账单。', $exception->getMessage());
        }

        $deleted = $this->client('invoice-deleted', 'invoice-deleted@example.com');
        $deleted->delete();

        try {
            app(InvoiceService::class)->generateNoPaymentRequired($deleted->fresh(), [[
                'type' => 'downgrade',
                'description' => '已删除客户无需付款账单',
                'amount' => 0,
            ]]);
            $this->fail('Expected no-payment invoice generation to reject deleted clients.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('客户账号状态不允许生成账单。', $exception->getMessage());
        }

        $this->assertDatabaseMissing('invoices', ['client_id' => $inactive->id]);
        $this->assertDatabaseMissing('invoices', ['client_id' => $deleted->id]);
    }

    public function test_invoice_add_item_rejects_amount_above_database_capacity(): void
    {
        $invoice = $this->invoice($this->client(), 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('账单金额超出允许范围');

        app(InvoiceService::class)->addItem($invoice, 'product', '超大追加明细', 100000000);
    }

    public function test_invoice_add_item_rejects_finalized_invoice(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Paid']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('只能给未支付账单追加明细');

        app(InvoiceService::class)->addItem($invoice->fresh(), 'product', '已支付后追加明细', 50);
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

    public function test_payment_callback_service_rejects_non_paid_status_even_when_gateway_accepts_payload(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        Facade::clearResolvedInstance('plugin.manager');
        $this->app->instance('plugin.manager', new class {
            public function get(string $name): ?PaymentGatewayInterface
            {
                return new class implements PaymentGatewayInterface {
                    public function getName(): string
                    {
                        return 'manual_pay';
                    }

                    public function getTitle(): string
                    {
                        return 'Unsafe Test Gateway';
                    }

                    public function getVersion(): string
                    {
                        return '1.0.0';
                    }

                    public function getType(): string
                    {
                        return 'gateway';
                    }

                    public function install(): bool
                    {
                        return true;
                    }

                    public function uninstall(): bool
                    {
                        return true;
                    }

                    public function getConfig(): array
                    {
                        return [];
                    }

                    public function setConfig(array $config): void
                    {
                    }

                    public function pay(array $order): array
                    {
                        return ['success' => true];
                    }

                    public function notify(array $data): bool
                    {
                        return true;
                    }

                    public function refund(string $transId, float $amount): bool
                    {
                        return true;
                    }

                    public function query(string $transId): array
                    {
                        return [];
                    }
                };
            }
        });

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'failed',
            'trans_id' => 'MANUAL-FAILED-STATUS',
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'MANUAL-FAILED-STATUS']);
        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'pending',
        ]);
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

    public function test_payment_callback_rejects_missing_transaction_id_without_server_error(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'paid',
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseHas('payment_attempts', [
            'invoice_id' => $invoice->id,
            'gateway' => 'manual_pay',
            'status' => 'pending',
        ]);
    }

    public function test_payment_callback_rejects_array_transaction_id_without_server_error(): void
    {
        $this->installManualPay();
        $invoice = $this->invoice($this->client(), 88.00);
        app(PaymentService::class)->processPayment($invoice, 'manual_pay', []);

        $result = app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 88.00,
            'status' => 'paid',
            'trans_id' => ['MANUAL-ARRAY-TRANS-001'],
        ]);

        $this->assertFalse($result);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['invoice_id' => $invoice->id]);
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

    public function test_full_refund_marks_host_for_manual_review_when_termination_fails(): void
    {
        $this->installMockServer(['fail_terminate' => true]);
        $client = $this->client();
        $invoice = $this->invoice($client, 100);
        $invoice->update(['status' => 'Paid']);
        $order = $this->order($client, $invoice);
        $order->update(['status' => 'Paid']);
        $host = $this->host($client, $order, ['status' => 'Active']);
        $host->product->update(['server_type' => 'mock_server']);

        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 100));

        $this->assertSame('Active', $host->fresh()->status);
        $this->assertStringContainsString('全额退款后服务终止失败，请人工处理', (string) $host->fresh()->admin_notes);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'terminate_failed',
            'message' => '服务器模块终止失败',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'refund_termination_pending',
            'message' => '全额退款后服务终止失败，请人工处理',
        ]);
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

    public function test_invoice_mark_paid_rejects_zero_amount_invoice(): void
    {
        $invoice = $this->invoice($this->client(), 0);

        $this->assertFalse(app(InvoiceService::class)->markAsPaid($invoice->fresh(), 'manual', 'ZERO-INVOICE-PAID-1'));
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'ZERO-INVOICE-PAID-1']);
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

    public function test_order_mark_paid_does_not_mark_order_when_invoice_amount_is_zero(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 0);
        $order = $this->order($client, $invoice);
        $order->update(['amount' => 0]);

        $this->assertFalse(app(OrderService::class)->markAsPaid($order, 'manual', 'ORDER-ZERO-PAID-1'));

        $this->assertSame('Pending', $order->fresh()->status);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'ORDER-ZERO-PAID-1']);
    }

    public function test_order_mark_paid_rejects_invoice_less_zero_amount_order(): void
    {
        $client = $this->client();
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-ZERO-' . random_int(100000, 999999),
            'status' => 'Pending',
            'amount' => 0,
            'currency_id' => 1,
        ]);

        $this->assertFalse(app(OrderService::class)->markAsPaid($order, 'manual', 'ORDER-ZERO-NO-INVOICE-1'));

        $this->assertSame('Pending', $order->fresh()->status);
    }

    public function test_order_mark_paid_rejects_invoice_less_order_for_inactive_client(): void
    {
        $client = $this->client();
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-INACTIVE-' . random_int(100000, 999999),
            'status' => 'Pending',
            'amount' => 100,
            'currency_id' => 1,
        ]);
        $client->update(['status' => 2]);

        $this->assertFalse(app(OrderService::class)->markAsPaid($order, 'manual', 'ORDER-INACTIVE-NO-INVOICE-1'));

        $this->assertSame('Pending', $order->fresh()->status);
    }

    public function test_order_mark_paid_rejects_invoice_less_order_when_transaction_id_is_reused(): void
    {
        $client = $this->client();
        $firstInvoice = $this->invoice($client, 100);
        Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $firstInvoice->id,
            'type' => 'credit',
            'amount' => 100,
            'fee' => 0,
            'payment_method' => 'manual',
            'gateway_trans_id' => 'ORDER-NO-INVOICE-DUPLICATE-1',
            'refunded' => 0,
        ]);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-DUP-' . random_int(100000, 999999),
            'status' => 'Pending',
            'amount' => 100,
            'currency_id' => 1,
        ]);

        $this->assertFalse(app(OrderService::class)->markAsPaid($order, 'manual', 'ORDER-NO-INVOICE-DUPLICATE-1'));

        $this->assertSame('Pending', $order->fresh()->status);
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

    public function test_admin_invoice_refund_rejects_amount_above_remaining_before_service_call(): void
    {
        $invoice = $this->invoice($this->client(), 100);
        $invoice->update(['status' => 'Paid']);
        $this->assertTrue(app(InvoiceService::class)->refund($invoice->fresh(), 40));

        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.invoices.refund', $invoice->fresh()), ['amount' => 70])
            ->assertSessionHasErrors('amount');

        $this->assertSame('Partially Refunded', $invoice->fresh()->status);
        $this->assertSame('60.00', number_format(app(InvoiceService::class)->remainingRefundableAmount($invoice->fresh()), 2, '.', ''));
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

    private function installMockServer(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        Plugin::query()->where('name', 'mock_server')->update(['config' => $config]);
    }

    private function installEpayAlipay(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'epay_alipay');
        $manager->enable('epay_alipay');
        Plugin::query()->where('name', 'epay_alipay')->update([
            'config' => $config + [
                'api_url' => 'https://pay.example.com/',
                'pid' => '1001',
                'key' => 'secret',
                'pay_type' => 'alipay',
                'return_url' => '',
            ],
        ]);
    }

    private function installAlipaySdk(array $config = []): void
    {
        [$privateKey, $publicKey] = $this->alipayKeyPair();
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'alipay_sdk');
        $manager->enable('alipay_sdk');
        Plugin::query()->where('name', 'alipay_sdk')->update([
            'config' => $config + [
                'app_id' => '2021000000000000',
                'private_key' => $privateKey,
                'alipay_public_key' => $publicKey,
                'sandbox' => true,
                'return_url' => '',
            ],
        ]);
    }

    private function installWechatPay(array $config = []): void
    {
        [$privateKey, $publicKey] = $this->alipayKeyPair();
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'wechat_pay');
        $manager->enable('wechat_pay');
        Plugin::query()->where('name', 'wechat_pay')->update([
            'config' => $config + [
                'mch_id' => '1900000001',
                'api_v3_key' => '12345678901234567890123456789012',
                'cert_serial' => 'TESTCERTSERIAL',
                'private_key' => $privateKey,
                'wechatpay_public_key' => $publicKey,
                'app_id' => 'wx1234567890abcdef',
            ],
        ]);
    }

    private function signedAlipayPayload(string $privateKey, array $overrides = []): array
    {
        $payload = $overrides + [
            'app_id' => '2021000000000000',
            'trade_no' => 'ALIPAY-TRADE-001',
            'out_trade_no' => '1',
            'total_amount' => '88.00',
            'trade_status' => 'TRADE_SUCCESS',
            'seller_id' => '2088000000000000',
            'timestamp' => '2026-05-25 12:00:00',
        ];
        $client = new AlipayClient([
            'app_id' => '2021000000000000',
            'private_key' => $privateKey,
            'alipay_public_key' => $this->alipayKeyPair()[1],
            'sandbox' => true,
        ]);
        $payload['sign_type'] = 'RSA2';
        $payload['sign'] = $client->sign($payload);

        return $payload;
    }

    private function signedWechatPayPayload(string $privateKey, string $publicKey, array $transactionOverrides = []): array
    {
        $transaction = $transactionOverrides + [
            'out_trade_no' => '1',
            'transaction_id' => 'WECHAT-TRADE-001',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 8800, 'currency' => 'CNY'],
        ];
        $body = json_encode($transaction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = '1779696000';
        $nonce = 'wechat-test-nonce';
        $signature = '';
        openssl_sign(
            $timestamp . "\n" . $nonce . "\n" . $body . "\n",
            $signature,
            openssl_pkey_get_private($privateKey),
            OPENSSL_ALGO_SHA256
        );

        return $transaction + [
            'wechatpay_timestamp' => $timestamp,
            'wechatpay_nonce' => $nonce,
            'wechatpay_signature' => base64_encode($signature),
            'wechatpay_body' => $body,
            'wechatpay_public_key' => $publicKey,
        ];
    }

    private function alipayKeyPair(): array
    {
        return [
            <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC4FdhaZVPlvxY3
X6r+7JKgOcYsgOo5ytoM4CdW+ysY2n2OJExrfDTOoY6kyW+MIy3rTobP15j8mWch
FfR8YncbZSmpWEhzhvPhqLi6GfWIiGOGDj9jCnLnjqC0r4fVa+wH0M7Mkrlg5Q5m
xUOFYge4dKqyeuyy9Nz1RZZyp4NOZ/OqxFL0muJgijAY+TOsiUBUsk0PZhSiLguT
unFXfGC/W6cgzP0htZPuw+XbDwG2eKNezpvQb+VwQg0J26AIEARjPRdV+4BinEwt
LZnf9nuN3WuqNLEKBoHdyOLx6OUa009y77bljuJM4A4alM3W/8CrNnW1gZVo4iv6
EnU6KDkvAgMBAAECggEAJp+avditwjIWILcnYwZS+2Az1smTm12W44Wya1sWn0fU
eRrfl9u/Hq2iBqwnBeGptEnNGlWziShMjZIUMnbcY7iVhaz6wpaJnAqw+4cPz75C
F3Hs1cRu+GuiB1ce6mYS507l3OFaGNzmaSSxdo5rbUW5POpyuFeM9r9LgjHoaG4m
o5mCnl0ZJxXwDQO2TdG3Alu+R0uJ/zgscbLdemCWhOjw3rsScDoa3UPwTPnoA+8M
XQ9d12cw5evXzVc6upOZxLKDWmZTcxiyWIgynBuEmgj3YhGd4jKAfw8gSnrR/wqZ
GIRvwRIiSxd+514ceN/t6oivblH4LUNnpkJ7TIby9QKBgQDuEEesU8eXsSdV0Jrv
x0zWt26xnEdDiDCGC9QlBGuXRYcdq7vD57klzuz5BLEIvNaoQrGdZq8zU/HND9pn
qNSY4rcIM7+08wotwkTls0glfp1m9xEwELF/TJOXpd4wWbNHo4Pdf2R3+zauoMvN
ZEhJ33Dp6uxUxEx2cx69vLwHBQKBgQDF9HGk1X/ikDM8YRP8GLHFbNg1qGNx3kC8
sAYctwpUI50BSSGMRgyk8i8MxJok/WkxJSXUY2wS1fQUbUPg/o35C55+X/wIU2i5
T51r2WJHnubG4TLKJ00CnNUg0bkFr/B899IXbFSFdYg7305fCNv+2BXTTRMw0E52
Jm1YBOqNowKBgQCL8xgfb3UTcPp90U90DEbYpyc01Hl0ctiLxOJnDI0vdZkz0SRl
y5ClcFsRHTfxugm7CtIdhSMT2pJ4iYxMigzI/+a3tKxLdOET+3PDUTzlheSEhlQd
XILsIhlV+hV/eQwS3kaD7QMkIZOI31BQI1b3zpozeX6LaobEz3JP+mbS/QKBgGeV
0FoG9pKiDo2L5x9F9NBwcnsxkEgnmwyht7ES/y6kLCZeFFYI2dj+eixePKMakA8N
d0w6cnUwzDZcLubvjW9C6z8KDyJ0Mxq1VJT4/fqoZe6wLRmnkx7I3qX72KvnMxrR
u3hSUbA8nntmEOaeBjDG9jTJ4j7q4gPle9ZRTEOtAoGAe5I2JjQLSeCtvRMavNuc
RIWVa4+GRmblaRiEr0G+gz7jc9pyoemE60oah6W+7xh2Y7BIWM7p/TgWtoSjA9R5
0ZE5fNsy3dLUnO1zUY1AvzT1wHufHpQuwWk6HzhlEjTF4I3osAP01778cE3p1Gee
8dt/kZjIKLxIW3W91ZRtBWo=
-----END PRIVATE KEY-----
PEM,
            <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuBXYWmVT5b8WN1+q/uyS
oDnGLIDqOcraDOAnVvsrGNp9jiRMa3w0zqGOpMlvjCMt606Gz9eY/JlnIRX0fGJ3
G2UpqVhIc4bz4ai4uhn1iIhjhg4/Ywpy546gtK+H1WvsB9DOzJK5YOUOZsVDhWIH
uHSqsnrssvTc9UWWcqeDTmfzqsRS9JriYIowGPkzrIlAVLJND2YUoi4Lk7pxV3xg
v1unIMz9IbWT7sPl2w8BtnijXs6b0G/lcEINCdugCBAEYz0XVfuAYpxMLS2Z3/Z7
jd1rqjSxCgaB3cji8ejlGtNPcu+25Y7iTOAOGpTN1v/AqzZ1tYGVaOIr+hJ1Oig5
LwIDAQAB
-----END PUBLIC KEY-----
PEM,
        ];
    }

    private function signedEpayPayload(array $overrides = []): array
    {
        $payload = $overrides + [
            'pid' => '1001',
            'trade_no' => 'EPAY-TRADE-001',
            'out_trade_no' => '1',
            'type' => 'alipay',
            'name' => '账单 #INV001',
            'money' => '88.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $client = new EpayClient('https://pay.example.com/', '1001', 'secret');
        $payload['sign'] = $client->referenceSign($payload);
        $payload['sign_type'] = 'MD5';

        return $payload;
    }

    private function bootPluginRoutesForTest(): void
    {
        $provider = new class($this->app) extends \App\Providers\PluginServiceProvider {
            public function bootRoutes(): void
            {
                $this->loadPluginRoutes();
            }
        };

        $provider->bootRoutes();
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
