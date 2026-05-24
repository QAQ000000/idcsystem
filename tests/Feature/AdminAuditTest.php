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
                    'access_key' => 'should-not-be-stored',
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $log = \App\Models\AdminActionLog::query()
            ->where('action', 'plugin.config.save')
            ->firstOrFail();

        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('[FILTERED]', $log->payload['config']['app_secret']);
        $this->assertSame('[FILTERED]', $log->payload['config']['access_key']);
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
