<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\HostActionLog;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServerModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_server_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('server'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'mock_server'));
        $this->assertTrue($manager->install('server', 'mock_server'));
        $this->assertDatabaseHas('plugins', ['name' => 'mock_server', 'type' => 'server', 'status' => 0]);

        $this->assertTrue($manager->enable('mock_server'));
        $this->assertDatabaseHas('plugins', ['name' => 'mock_server', 'status' => 1]);
    }

    public function test_admin_can_bind_product_to_server_type(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $group = ProductGroup::query()->create(['name' => '服务器产品']);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.products.store'), [
            'group_id' => $group->id,
            'name' => 'Mock VPS',
            'type' => 'vps',
            'auto_setup' => 'payment',
            'server_type' => 'mock_server',
            'stock_qty' => 10,
        ]);

        $product = Product::query()->where('name', 'Mock VPS')->firstOrFail();
        $response->assertRedirect(route('admin.products.show', $product));
        $this->assertSame('mock_server', $product->server_type);
    }

    public function test_admin_product_rejects_missing_group_and_disabled_server_type(): void
    {
        app(PluginManager::class)->install('server', 'mock_server');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.products.store'), [
            'group_id' => 999999,
            'name' => 'Invalid Group VPS',
            'type' => 'vps',
            'server_type' => 'mock_server',
            'stock_qty' => 10,
        ])->assertSessionHasErrors(['group_id', 'server_type']);

        $group = ProductGroup::query()->create(['name' => '服务器产品']);
        $this->actingAs($admin, 'admin')->post(route('admin.products.store'), [
            'group_id' => $group->id,
            'name' => 'Disabled Server VPS',
            'type' => 'vps',
            'server_type' => 'mock_server',
            'stock_qty' => 10,
        ])->assertSessionHasErrors('server_type');
    }

    public function test_admin_product_edit_preserves_existing_disabled_server_type(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $group = ProductGroup::query()->create(['name' => '服务器产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Existing Mock VPS',
            'type' => 'vps',
            'auto_setup' => 'payment',
            'server_type' => 'mock_server',
            'stock_qty' => 10,
        ]);
        Plugin::query()->where('name', 'mock_server')->update(['status' => 0]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('已停用');

        $this->actingAs($admin, 'admin')->put(route('admin.products.update', $product), [
            'group_id' => $group->id,
            'name' => 'Existing Mock VPS Updated',
            'type' => 'vps',
            'auto_setup' => 'payment',
            'server_type' => 'mock_server',
            'stock_qty' => 10,
        ])->assertRedirect(route('admin.products.show', $product));

        $this->assertSame('mock_server', $product->fresh()->server_type);
    }

    public function test_admin_cannot_delete_product_with_existing_host(): void
    {
        $admin = $this->admin();
        $host = $this->host();
        $product = $host->product;

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHas('error', '产品存在关联服务，不能删除');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'product.delete',
            'target_id' => $product->id,
            'result' => 'failed',
            'error' => '产品存在关联服务，不能删除',
        ]);
    }

    public function test_admin_product_delete_cleans_product_configuration_rows(): void
    {
        $admin = $this->admin();
        $product = $this->product('Disposable Mock VPS', 50);
        \DB::table('custom_fields')->insert([
            'type' => 'product',
            'rel_id' => $product->id,
            'field_name' => 'hostname',
            'field_type' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status', '产品已删除');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('pricings', [
            'type' => 'product',
            'rel_id' => $product->id,
        ]);
        $this->assertDatabaseMissing('custom_fields', [
            'type' => 'product',
            'rel_id' => $product->id,
        ]);
    }

    public function test_paid_order_provisions_host_through_mock_server(): void
    {
        Mail::fake();
        $this->installMockServer();
        $client = $this->client();
        $product = $this->product('Mock VPS', 50);

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);

        $this->assertTrue(app(OrderService::class)->markAsPaid($order->fresh(['invoice', 'hosts.product']), 'manual_pay', 'SERVER-001'));

        $host = $order->hosts()->firstOrFail()->fresh();
        $this->assertSame('Active', $host->status);
        $this->assertSame('mock' . $host->id, $host->username);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_gateway_callback_for_order_invoice_provisions_pending_host(): void
    {
        Mail::fake();
        $this->installMockServer();
        $this->installManualPay();
        $client = $this->client();
        $product = $this->product('Callback Mock VPS', 50);

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);

        $host = $order->hosts()->firstOrFail();
        $this->assertSame('Pending', $host->status);
        app(PaymentService::class)->processPayment($order->invoice, 'manual_pay', []);

        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $order->invoice_id,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'SERVER-CALLBACK-001',
        ]));

        $host->refresh();
        $this->assertSame('Active', $host->status);
        $this->assertSame('mock' . $host->id, $host->username);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_gateway_callback_logs_provision_failure_without_marking_host_active(): void
    {
        Mail::fake();
        $this->installMockServer(['fail_create' => true]);
        $this->installManualPay();
        $client = $this->client();
        $product = $this->product('Failing Mock VPS', 50);

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);
        $host = $order->hosts()->firstOrFail();
        app(PaymentService::class)->processPayment($order->invoice, 'manual_pay', []);

        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $order->invoice_id,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'SERVER-CALLBACK-FAIL-001',
        ]));

        $this->assertSame('Paid', $order->fresh()->status);
        $this->assertSame('Pending', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => 'MockServer 模拟开通失败',
        ]);
    }

    public function test_host_action_logs_mask_sensitive_message_and_meta(): void
    {
        $admin = $this->admin();
        $host = $this->host(['status' => 'Pending']);

        $log = HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => '模块失败 password=plain-secret token:token-value',
            'meta' => [
                'result' => [
                    'password' => 'result-password',
                    'access_token' => 'result-token',
                    'api_key' => 'result-key',
                    'signature' => 'result-signature',
                    'message' => 'safe message',
                ],
            ],
        ]);

        $log->refresh();
        $this->assertSame('模块失败 password=[FILTERED] token:[FILTERED]', $log->message);
        $this->assertSame('[FILTERED]', $log->meta['result']['password']);
        $this->assertSame('[FILTERED]', $log->meta['result']['access_token']);
        $this->assertSame('[FILTERED]', $log->meta['result']['api_key']);
        $this->assertSame('[FILTERED]', $log->meta['result']['signature']);
        $this->assertSame('safe message', $log->meta['result']['message']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('password=[FILTERED]')
            ->assertDontSee('plain-secret')
            ->assertDontSee('token-value')
            ->assertDontSee('result-password')
            ->assertDontSee('result-token')
            ->assertDontSee('result-key')
            ->assertDontSee('result-signature');
    }

    public function test_provision_fails_when_bound_server_module_is_disabled(): void
    {
        $this->installMockServer();
        Plugin::query()->where('name', 'mock_server')->update(['status' => 0]);
        app(PluginManager::class)->forget('mock_server', 'server');
        $host = $this->host(['status' => 'Pending']);
        $host->product->update(['server_type' => 'mock_server']);

        $this->assertFalse(app(\App\Modules\Order\Services\HostService::class)->provision($host->fresh(['product'])));

        $this->assertSame('Pending', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => '服务器模块不可用：mock_server',
        ]);
        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_admin_can_suspend_unsuspend_and_terminate_host(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $host = $this->host();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'suspend', 'reason' => '测试暂停'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Suspended', $host->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'unsuspend'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Active', $host->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'terminate'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Terminated', $host->fresh()->status);

        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'suspend']);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'unsuspend']);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'terminate']);
    }

    public function test_admin_host_actions_reject_inactive_client_for_provision_unsuspend_and_reset_password(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $host = $this->host(['status' => 'Suspended']);
        $host->client->update(['status' => 2]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'provision'])
            ->assertRedirect(route('admin.hosts.show', $host))
            ->assertSessionHas('error', '服务操作失败，请查看操作日志');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'unsuspend'])
            ->assertRedirect(route('admin.hosts.show', $host))
            ->assertSessionHas('error', '服务操作失败，请查看操作日志');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'reset_password'])
            ->assertRedirect(route('admin.hosts.show', $host))
            ->assertSessionHas('error', '服务操作失败，请查看操作日志');

        $this->assertSame('Suspended', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => '客户账号状态不允许开通服务',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'unsuspend_failed',
            'message' => '客户账号状态不允许解除暂停',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'reset_password_failed',
            'message' => '客户账号状态不允许重置密码',
        ]);
    }

    public function test_admin_host_actions_still_allow_suspend_and_terminate_for_inactive_client(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $host = $this->host(['status' => 'Active']);
        $host->client->update(['status' => 2]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'suspend', 'reason' => '停用客户收尾'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Suspended', $host->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'terminate'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Terminated', $host->fresh()->status);
    }

    public function test_admin_host_detail_hides_service_recovery_actions_for_inactive_client(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Suspended']);
        $host->client->update(['status' => 2]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('客户账号不可用，不能执行开通、解除暂停或重置密码。')
            ->assertDontSee('重试开通')
            ->assertSee('终止');
    }

    public function test_admin_host_detail_does_not_show_provision_action_for_suspended_host(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Suspended']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertDontSee('开通服务')
            ->assertDontSee('重试开通')
            ->assertSee('解除暂停')
            ->assertSee('终止');
    }

    public function test_provision_is_not_repeated_for_active_host(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Active']);

        $this->assertFalse(app(\App\Modules\Order\Services\HostService::class)->provision($host));

        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision_failed',
        ]);
    }

    public function test_provision_rechecks_latest_host_status_before_calling_server_module(): void
    {
        Mail::fake();
        $this->installMockServer();
        $client = $this->client();
        $product = $this->product('Stale Host VPS', 50);

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);

        $host = $order->hosts()->firstOrFail();
        $stale = $host->fresh();
        $host->update(['status' => 'Active']);

        $this->assertFalse(app(\App\Modules\Order\Services\HostService::class)->provision($stale));
        $this->assertSame('Active', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision_failed',
        ]);
    }

    public function test_provision_rejects_unpaid_order_host(): void
    {
        Mail::fake();
        $this->installMockServer();
        $client = $this->client();
        $product = $this->product('Unpaid Provision VPS', 50);

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);

        $host = $order->hosts()->firstOrFail();

        $this->assertFalse(app(\App\Modules\Order\Services\HostService::class)->provision($host->fresh(['client', 'product'])));
        $this->assertSame('Pending', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => '关联订单或账单未支付，不能开通服务',
        ]);
        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_admin_host_detail_hides_provision_action_for_unpaid_order_host(): void
    {
        Mail::fake();
        $this->installMockServer();
        $client = $this->client();
        $product = $this->product('Unpaid Admin Provision VPS', 50);

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);
        $host = $order->hosts()->firstOrFail();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('关联订单或账单未支付，不能开通服务。')
            ->assertDontSee('name="action" value="provision"', false)
            ->assertDontSee('重试开通');
    }

    public function test_failed_host_actions_are_logged(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Pending']);
        $service = app(\App\Modules\Order\Services\HostService::class);

        $this->assertFalse($service->suspend($host, '状态不允许'));
        $this->assertFalse($service->terminate($host));
        $this->assertFalse($service->resetPassword($host));

        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'suspend_failed']);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'terminate_failed']);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'reset_password_failed']);
    }

    public function test_host_actions_fail_cleanly_when_bound_server_module_is_disabled(): void
    {
        $this->installMockServer();
        Plugin::query()->where('name', 'mock_server')->update(['status' => 0]);
        app(PluginManager::class)->forget('mock_server', 'server');

        $service = app(\App\Modules\Order\Services\HostService::class);
        $active = $this->host(['status' => 'Active']);
        $active->product->update(['server_type' => 'mock_server']);
        $suspended = $this->host(['status' => 'Suspended']);
        $suspended->product->update(['server_type' => 'mock_server']);

        $this->assertFalse($service->suspend($active->fresh(['client', 'product']), '模块停用'));
        $this->assertFalse($service->terminate($active->fresh(['client', 'product'])));
        $this->assertFalse($service->resetPassword($active->fresh(['client', 'product'])));
        $this->assertFalse($service->unsuspend($suspended->fresh(['client', 'product'])));

        $this->assertSame('Active', $active->fresh()->status);
        $this->assertSame('Suspended', $suspended->fresh()->status);
        foreach (['suspend_failed', 'terminate_failed', 'reset_password_failed'] as $action) {
            $this->assertDatabaseHas('host_action_logs', [
                'host_id' => $active->id,
                'action' => $action,
                'message' => '服务器模块不可用：mock_server',
            ]);
        }
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $suspended->id,
            'action' => 'unsuspend_failed',
            'message' => '服务器模块不可用：mock_server',
        ]);
    }

    public function test_admin_host_detail_shows_usage_stats_and_failure_reason(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Pending']);
        \App\Models\HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => 'MockServer 模拟开通失败',
            'meta' => ['result' => ['success' => false]],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('用量统计')
            ->assertSee('最近失败原因')
            ->assertSee('MockServer 模拟开通失败');
    }

    public function test_admin_host_detail_handles_usage_stats_failure(): void
    {
        $this->installMockServer(['fail_usage' => true]);
        $host = $this->host(['status' => 'Active']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('实时用量读取失败')
            ->assertSee('MockServer 模拟用量采集失败');

        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'usage_query_failed',
            'message' => 'MockServer 模拟用量采集失败',
        ]);
    }

    public function test_admin_host_detail_does_not_repeat_recent_usage_stats_failure_log(): void
    {
        $this->installMockServer(['fail_usage' => true]);
        $host = $this->host(['status' => 'Active']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk();

        $this->assertSame(1, \App\Models\HostActionLog::query()
            ->where('host_id', $host->id)
            ->where('action', 'usage_query_failed')
            ->where('message', 'MockServer 模拟用量采集失败')
            ->count());
    }

    public function test_admin_host_detail_does_not_treat_non_provision_failure_as_provision_retry(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Pending']);
        \App\Models\HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => 'suspend_failed',
            'message' => '当前服务状态不允许暂停',
            'meta' => ['status' => 'Pending'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertDontSee('最近失败原因')
            ->assertDontSee('重试开通')
            ->assertSee('开通服务')
            ->assertSee('suspend_failed');
    }

    public function test_admin_host_detail_clears_provision_failure_indicator_after_successful_retry(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $host = $this->host(['status' => 'Pending']);
        \App\Models\HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => '首次开通失败',
            'meta' => [],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('存在失败操作')
            ->assertSee('最近失败原因')
            ->assertSee('重试开通');

        $this->assertTrue(app(\App\Modules\Order\Services\HostService::class)->provision($host->fresh(['client', 'product'])));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host->fresh()))
            ->assertOk()
            ->assertDontSee('存在失败操作')
            ->assertDontSee('最近失败原因')
            ->assertDontSee('重试开通')
            ->assertSee('Active');
    }

    public function test_admin_host_list_can_filter_by_client_product_and_status(): void
    {
        $admin = $this->admin();
        $host = $this->host(['status' => 'Suspended']);
        $other = $this->host(['status' => 'Active']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.index', [
                'client_id' => $host->client_id,
                'product_id' => $host->product_id,
                'status' => 'Suspended',
            ]))
            ->assertOk()
            ->assertSee('#' . $host->id)
            ->assertDontSee('#' . $other->id);
    }

    public function test_admin_host_list_filters_ignore_array_query_values(): void
    {
        $admin = $this->admin();
        $host = $this->host(['status' => 'Active']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.index', [
                'client_id' => [$host->client_id],
                'product_id' => [$host->product_id],
                'status' => ['Active'],
            ]))
            ->assertOk()
            ->assertSee('#' . $host->id);
    }

    public function test_admin_host_detail_usage_query_does_not_pass_host_password_to_server_module(): void
    {
        $this->installMockServer(['fail_when_usage_receives_password' => true]);
        $admin = $this->admin();
        $host = $this->host(['status' => 'Active']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertDontSee('Usage stats should not receive host password');
    }

    private function installMockServer(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        Plugin::query()->where('name', 'mock_server')->update(['config' => $config]);
    }

    private function installManualPay(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        Plugin::query()->where('name', 'manual_pay')->update(['config' => []]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'admin-server-' . random_int(1000, 9999),
            'email' => 'admin-server-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'server-client-' . random_int(1000, 9999),
            'email' => 'server-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name, float $monthly): Product
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $group = ProductGroup::query()->firstOrCreate(['name' => 'MockServer 产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => $name,
            'description' => $name . ' 产品说明',
            'type' => 'vps',
            'auto_setup' => 'payment',
            'server_type' => 'mock_server',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => $monthly,
        ]);

        return $product;
    }

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $product = $this->product('Admin Mock VPS ' . random_int(1000, 9999), 50);
        $order = \App\Modules\Order\Models\Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-SERVER-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $client->currency_id,
        ]);

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'next_due_date' => now()->addMonth(),
            'next_invoice_date' => now()->addDays(23),
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides))->load(['client', 'product']);
    }
}
