<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\AdminActionLog;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_invoice_mark_paid_writes_operator_audit_log(): void
    {
        $admin = $this->admin();
        $invoice = $this->invoice($this->client(), 100);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.invoices.mark-paid', $invoice), [
                'payment_method' => 'manual',
                'trans_id' => 'AUDIT-INVOICE-PAID-1',
            ])
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'invoice.mark_paid',
            'target_type' => Invoice::class,
            'target_id' => $invoice->id,
            'result' => 'success',
        ]);
    }

    public function test_admin_failed_host_action_writes_failed_audit_log(): void
    {
        $admin = $this->admin();
        $host = $this->host(['status' => 'Pending']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'suspend'])
            ->assertRedirect(route('admin.hosts.show', $host));

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'host.suspend',
            'target_type' => Host::class,
            'target_id' => $host->id,
            'result' => 'failed',
        ]);
    }

    public function test_admin_plugin_config_audit_masks_sensitive_values(): void
    {
        $admin = $this->admin();
        app(PluginManager::class)->install('gateway', 'manual_pay');
        $plugin = Plugin::query()->where('name', 'manual_pay')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.config.save', $plugin->name), [
                'config' => [
                    'app_id' => 'audit-app',
                    'app_secret' => 'should-not-be-stored',
                    'access_key_id' => 'should-not-be-stored',
                    'access_key' => 'should-not-be-stored',
                    'authorization' => 'should-not-be-stored',
                    'cookie' => 'should-not-be-stored',
                    'session_id' => 'should-not-be-stored',
                    'bearer_token' => 'should-not-be-stored',
                    'signature' => 'should-not-be-stored',
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $log = \App\Models\AdminActionLog::query()
            ->where('action', 'plugin.config.save')
            ->firstOrFail();

        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('[FILTERED]', $log->payload['config']['app_secret']);
        $this->assertSame('[FILTERED]', $log->payload['config']['access_key_id']);
        $this->assertSame('[FILTERED]', $log->payload['config']['access_key']);
        $this->assertSame('[FILTERED]', $log->payload['config']['authorization']);
        $this->assertSame('[FILTERED]', $log->payload['config']['cookie']);
        $this->assertSame('[FILTERED]', $log->payload['config']['session_id']);
        $this->assertSame('[FILTERED]', $log->payload['config']['bearer_token']);
        $this->assertSame('[FILTERED]', $log->payload['config']['signature']);
        $this->assertSame('audit-app', $log->payload['config']['app_id']);
    }

    public function test_admin_setting_update_writes_audit_log(): void
    {
        $admin = $this->admin();
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.update'), [
                'site_name' => 'Audit IDC',
                'site_url' => 'http://localhost',
                'default_currency' => 'CNY',
                'auto_setup_policy' => 'manual',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 7,
                'mail_from_name' => 'Audit IDC',
                'mail_from_address' => 'audit@example.com',
                'smtp_password' => 'should-not-be-stored',
                'default_email_provider' => 'smtp',
                'default_sms_provider' => 'aliyun',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $log = \App\Models\AdminActionLog::query()
            ->where('action', 'settings.update')
            ->firstOrFail();

        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('[FILTERED]', $log->payload['smtp_password']);
    }

    public function test_admin_audit_masks_sensitive_error_text(): void
    {
        $admin = $this->admin();

        app(\App\Services\AdminAuditService::class)->record(
            request(),
            'audit.sensitive_error',
            null,
            'failed',
            ['safe' => 'visible'],
            '连接失败 password=plain-secret token:token-value authorization=auth-value cookie:cookie-value session=session-value bearer=bearer-value access_key=key-value signature=sign-value'
        );

        $log = AdminActionLog::query()
            ->where('action', 'audit.sensitive_error')
            ->firstOrFail();

        $this->assertSame('连接失败 password=[FILTERED] token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] access_key=[FILTERED] signature=[FILTERED]', $log->error);
        $this->assertStringNotContainsString('plain-secret', (string) $log->error);
        $this->assertStringNotContainsString('token-value', (string) $log->error);
        $this->assertStringNotContainsString('auth-value', (string) $log->error);
        $this->assertStringNotContainsString('cookie-value', (string) $log->error);
        $this->assertStringNotContainsString('session-value', (string) $log->error);
        $this->assertStringNotContainsString('bearer-value', (string) $log->error);
        $this->assertStringNotContainsString('key-value', (string) $log->error);
        $this->assertStringNotContainsString('sign-value', (string) $log->error);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admin-action-logs.show', $log))
            ->assertOk()
            ->assertSee('password=[FILTERED]')
            ->assertDontSee('plain-secret')
            ->assertDontSee('token-value')
            ->assertDontSee('key-value')
            ->assertDontSee('sign-value');
    }

    public function test_admin_action_log_model_masks_sensitive_payload_and_error_text(): void
    {
        $log = AdminActionLog::query()->create([
            'admin_user_id' => null,
            'action' => 'audit.model_mask',
            'result' => 'failed',
            'payload' => [
                'access_token' => 'audit-token',
                'authorization' => 'audit-auth',
                'cookie' => 'audit-cookie',
                'session_id' => 'audit-session',
                'bearer_token' => 'audit-bearer',
                'nested' => [
                    'api_key' => 'audit-key',
                    'signature' => 'audit-signature',
                    'message' => 'visible',
                ],
            ],
            'error' => '审计失败 password=plain-secret token:token-value authorization=auth-value cookie:cookie-value session=session-value bearer=bearer-value access_key=key-value signature=sign-value',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'AuditTest',
        ]);

        $log->refresh();
        $this->assertSame('[FILTERED]', $log->payload['access_token']);
        $this->assertSame('[FILTERED]', $log->payload['authorization']);
        $this->assertSame('[FILTERED]', $log->payload['cookie']);
        $this->assertSame('[FILTERED]', $log->payload['session_id']);
        $this->assertSame('[FILTERED]', $log->payload['bearer_token']);
        $this->assertSame('[FILTERED]', $log->payload['nested']['api_key']);
        $this->assertSame('[FILTERED]', $log->payload['nested']['signature']);
        $this->assertSame('visible', $log->payload['nested']['message']);
        $this->assertSame('审计失败 password=[FILTERED] token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] access_key=[FILTERED] signature=[FILTERED]', $log->error);
        $this->assertStringNotContainsString('audit-token', json_encode($log->payload));
        $this->assertStringNotContainsString('audit-auth', json_encode($log->payload));
        $this->assertStringNotContainsString('audit-cookie', json_encode($log->payload));
        $this->assertStringNotContainsString('audit-session', json_encode($log->payload));
        $this->assertStringNotContainsString('audit-bearer', json_encode($log->payload));
        $this->assertStringNotContainsString('audit-key', json_encode($log->payload));
        $this->assertStringNotContainsString('audit-signature', json_encode($log->payload));
        $this->assertStringNotContainsString('plain-secret', (string) $log->error);
        $this->assertStringNotContainsString('token-value', (string) $log->error);
        $this->assertStringNotContainsString('auth-value', (string) $log->error);
        $this->assertStringNotContainsString('cookie-value', (string) $log->error);
        $this->assertStringNotContainsString('session-value', (string) $log->error);
        $this->assertStringNotContainsString('bearer-value', (string) $log->error);
        $this->assertStringNotContainsString('key-value', (string) $log->error);
        $this->assertStringNotContainsString('sign-value', (string) $log->error);
    }

    public function test_admin_audit_masks_sensitive_user_agent_text(): void
    {
        $admin = $this->admin();

        $this->withHeader('User-Agent', 'AuditBrowser token:ua-token authorization=ua-auth cookie:ua-cookie session=ua-session bearer=ua-bearer password=ua-secret signature=ua-sign')
            ->actingAs($admin, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $log = AdminActionLog::query()
            ->where('admin_user_id', $admin->id)
            ->where('action', 'admin.logout')
            ->firstOrFail();

        $this->assertSame('AuditBrowser token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] password=[FILTERED] signature=[FILTERED]', $log->user_agent);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admin-action-logs.show', $log))
            ->assertOk()
            ->assertSee('token:[FILTERED]')
            ->assertDontSee('ua-token')
            ->assertDontSee('ua-auth')
            ->assertDontSee('ua-cookie')
            ->assertDontSee('ua-session')
            ->assertDontSee('ua-bearer')
            ->assertDontSee('ua-secret')
            ->assertDontSee('ua-sign');
    }

    public function test_admin_action_log_model_masks_sensitive_user_agent_text(): void
    {
        $log = AdminActionLog::query()->create([
            'admin_user_id' => null,
            'action' => 'audit.user_agent_model_mask',
            'result' => 'success',
            'payload' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'ModelUA token:ua-token authorization=ua-auth cookie:ua-cookie session=ua-session bearer=ua-bearer password=ua-secret signature=ua-sign',
        ]);

        $log->refresh();

        $this->assertSame('ModelUA token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] password=[FILTERED] signature=[FILTERED]', $log->user_agent);
        $this->assertStringNotContainsString('ua-token', (string) $log->user_agent);
        $this->assertStringNotContainsString('ua-auth', (string) $log->user_agent);
        $this->assertStringNotContainsString('ua-cookie', (string) $log->user_agent);
        $this->assertStringNotContainsString('ua-session', (string) $log->user_agent);
        $this->assertStringNotContainsString('ua-bearer', (string) $log->user_agent);
        $this->assertStringNotContainsString('ua-secret', (string) $log->user_agent);
        $this->assertStringNotContainsString('ua-sign', (string) $log->user_agent);
    }

    public function test_super_admin_can_view_admin_action_logs(): void
    {
        $admin = $this->admin();
        $log = AdminActionLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => 'audit.visible',
            'target_type' => Invoice::class,
            'target_id' => 123,
            'result' => 'failed',
            'payload' => ['foo' => 'bar'],
            'error' => '测试错误',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'AuditTest',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admin-action-logs.index', ['action' => 'audit.visible', 'result' => 'failed']))
            ->assertOk()
            ->assertSee('audit.visible')
            ->assertSee('测试错误');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admin-action-logs.show', $log))
            ->assertOk()
            ->assertSee('审计详情')
            ->assertSee('audit.visible')
            ->assertSee('测试错误')
            ->assertSee('AuditTest');
    }

    public function test_admin_action_log_filters_ignore_array_query_values(): void
    {
        $admin = $this->admin();
        AdminActionLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => 'audit.array_filter',
            'result' => 'failed',
            'payload' => ['foo' => 'bar'],
            'error' => '数组筛选测试',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'AuditTest',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admin-action-logs.index', [
                'action' => ['audit.array_filter'],
                'result' => ['failed'],
            ]))
            ->assertOk()
            ->assertSee('audit.array_filter');
    }

    public function test_non_super_admin_cannot_view_admin_action_logs(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'audit-limited-' . random_int(1000, 9999),
            'email' => 'audit-limited-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admin-action-logs.index'))
            ->assertForbidden();
    }

    public function test_admin_layout_hides_privileged_navigation_links_for_limited_admin(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'nav-limited-' . random_int(1000, 9999),
            'email' => 'nav-limited-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('客户')
            ->assertDontSee('产品')
            ->assertDontSee('服务')
            ->assertDontSee('订单')
            ->assertDontSee('账单')
            ->assertDontSee('工单')
            ->assertDontSee('通知中心')
            ->assertDontSee('系统任务')
            ->assertDontSee('后台审计')
            ->assertDontSee('插件')
            ->assertDontSee('设置');
    }

    public function test_permission_denied_admin_write_attempt_is_audited(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'audit-denied-' . random_int(1000, 9999),
            'email' => 'audit-denied-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        $host = $this->host();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'suspend'])
            ->assertForbidden();

        $log = AdminActionLog::query()
            ->where('admin_user_id', $admin->id)
            ->where('action', 'admin.permission.denied')
            ->firstOrFail();

        $this->assertSame('failed', $log->result);
        $this->assertSame('host.manage', $log->payload['permission']);
        $this->assertSame('admin/hosts/' . $host->id . '/action', $log->payload['path']);
    }

    public function test_business_view_routes_require_view_permission(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'view-denied-' . random_int(1000, 9999),
            'email' => 'view-denied-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        $host = $this->host();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.invoices.index'))
            ->assertForbidden();
    }

    public function test_host_operation_panel_hides_actions_for_limited_admin(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'host-view-limited-' . random_int(1000, 9999),
            'email' => 'host-view-limited-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'host.view', 'guard_name' => 'web']));
        $host = $this->host();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('当前账号没有服务操作权限。')
            ->assertDontSee('暂停')
            ->assertDontSee('终止')
            ->assertDontSee('重置密码');
    }

    public function test_product_view_permission_does_not_grant_product_management_forms(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'product-view-limited-' . random_int(1000, 9999),
            'email' => 'product-view-limited-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'product.view', 'guard_name' => 'web']));
        $product = $this->product();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee($product->name);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.products.create'))
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.products.edit', $product))
            ->assertForbidden();
    }

    public function test_admin_logout_writes_audit_log(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.logout',
            'result' => 'success',
        ]);
    }

    public function test_inactive_admin_access_is_audited_before_logout(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'audit-disabled-' . random_int(1000, 9999),
            'email' => 'audit-disabled-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 2,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));

        $log = AdminActionLog::query()
            ->where('admin_user_id', $admin->id)
            ->where('action', 'admin.status.denied')
            ->firstOrFail();

        $this->assertSame('failed', $log->result);
        $this->assertSame(2, $log->payload['status']);
        $this->assertSame('admin', $log->payload['path']);
    }

    public function test_admin_status_middleware_rechecks_latest_database_status(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'audit-stale-disabled-' . random_int(1000, 9999),
            'email' => 'audit-stale-disabled-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);
        $staleAdmin = $admin->fresh();

        $admin->update(['status' => 2]);

        $this->actingAs($staleAdmin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.status.denied',
            'result' => 'failed',
        ]);
    }

    public function test_admin_permission_middleware_uses_refreshed_admin_roles(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'audit-stale-role-' . random_int(1000, 9999),
            'email' => 'audit-stale-role-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        $staleAdmin = $admin->fresh();
        $staleAdmin->load('roles');
        $admin->syncRoles(['support-admin']);

        $this->actingAs($staleAdmin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertForbidden();

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.permission.denied',
            'result' => 'failed',
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'audit-admin-' . random_int(1000, 9999),
            'email' => 'audit-admin-' . random_int(1000, 9999) . '@example.com',
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
            'username' => 'audit-client-' . random_int(1000, 9999),
            'email' => 'audit-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function invoice(Client $client, float $amount): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-AUDIT-' . random_int(1000, 9999),
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
            'description' => '审计测试账单',
            'amount' => $amount,
        ]);

        return $invoice;
    }

    private function product(): Product
    {
        $group = ProductGroup::query()->create(['name' => '审计产品组-' . random_int(1000, 9999)]);

        return Product::query()->create([
            'group_id' => $group->id,
            'name' => '审计产品-' . random_int(1000, 9999),
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);
    }

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $group = ProductGroup::query()->create(['name' => '审计产品组']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => '审计 VPS',
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);
        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $client->currency_id,
            'monthly' => 50,
        ]);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-AUDIT-' . random_int(1000, 9999),
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
