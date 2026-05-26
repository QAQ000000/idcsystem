<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Plugin;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_email_template(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $template = EmailTemplate::query()->where('name', 'invoice_created')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.email-templates.update', $template), [
                'subject' => '新账单 {{invoice_number}}',
                'body' => '金额 {{amount}}',
                'enabled' => '1',
            ])
            ->assertRedirect(route('admin.email-templates.index'));

        $this->assertSame('新账单 {{invoice_number}}', $template->fresh()->subject);
    }

    public function test_admin_can_edit_sms_template(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $template = SmsTemplate::query()->where('name', 'invoice_created')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.sms-templates.update', $template), [
                'content' => '短信账单 {{invoice_number}}',
                'enabled' => '1',
            ])
            ->assertRedirect(route('admin.sms-templates.index'));

        $this->assertSame('短信账单 {{invoice_number}}', $template->fresh()->content);
    }

    public function test_admin_email_template_edit_ignores_array_old_input_values(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $template = EmailTemplate::query()->where('name', 'invoice_created')->firstOrFail();

        session()->flashInput([
            'subject' => ['polluted'],
            'body' => ['polluted'],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.email-templates.edit', $template))
            ->assertOk()
            ->assertSee($template->subject)
            ->assertSee($template->body)
            ->assertDontSee('polluted');
    }

    public function test_admin_can_preview_and_send_test_email_template(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $template = EmailTemplate::query()->where('name', 'invoice_paid')->firstOrFail();
        $template->update([
            'subject' => '支付成功 {{invoice_number}}',
            'body' => '<p>{{client_name}} 已支付 {{amount}}</p>',
            'enabled' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.email-templates.preview', $template))
            ->assertOk()
            ->assertSee('支付成功 INV-TEST-001')
            ->assertSee('测试客户 已支付 99.00', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.email-templates.test', $template), [
                'email' => 'ops@example.com',
            ])
            ->assertRedirect(route('admin.email-templates.preview', $template))
            ->assertSessionHas('status', '测试邮件已发送');

        $this->assertDatabaseHas('email_logs', [
            'to' => 'ops@example.com',
            'template' => 'invoice_paid',
            'status' => 'sent',
        ]);
    }

    public function test_admin_sms_template_edit_ignores_array_old_input_values(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $template = SmsTemplate::query()->where('name', 'invoice_created')->firstOrFail();

        session()->flashInput([
            'content' => ['polluted'],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.sms-templates.edit', $template))
            ->assertOk()
            ->assertSee($template->content)
            ->assertDontSee('polluted');
    }

    public function test_admin_can_preview_and_send_test_sms_template(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSms();
        $template = SmsTemplate::query()->where('name', 'invoice_paid')->firstOrFail();
        $template->update([
            'content' => '短信 {{client_name}} {{invoice_number}} {{amount}}',
            'enabled' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.sms-templates.preview', $template))
            ->assertOk()
            ->assertSee('短信 测试客户 INV-TEST-001 99.00');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.sms-templates.test', $template), [
                'phone' => '13800138000',
            ])
            ->assertRedirect(route('admin.sms-templates.preview', $template))
            ->assertSessionHas('status', '测试短信已发送');

        $this->assertDatabaseHas('sms_logs', [
            'phone' => '13800138000',
            'template' => 'invoice_paid',
            'status' => 'sent',
        ]);
    }

    public function test_disabled_templates_do_not_send_notifications(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        EmailTemplate::query()->where('name', 'invoice_created')->update(['enabled' => false]);
        SmsTemplate::query()->where('name', 'invoice_created')->update(['enabled' => false]);
        $this->installSmtp();
        $this->installSms();

        app(InvoiceService::class)->generate($this->client(), [[
            'type' => 'product',
            'description' => '测试产品',
            'amount' => 100,
        ]]);

        $this->assertDatabaseHas('email_logs', ['template' => 'invoice_created', 'status' => 'failed']);
        $this->assertDatabaseHas('sms_logs', ['template' => 'invoice_created', 'status' => 'failed']);
    }

    public function test_closed_notification_policy_does_not_write_logs(): void
    {
        Mail::fake();
        $settings = app(SettingsService::class);
        $settings->set('notify_invoice_created_mail', false, 'notification');
        $settings->set('notify_invoice_created_sms', false, 'notification');
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSmtp();
        $this->installSms();

        app(InvoiceService::class)->generate($this->client(), [[
            'type' => 'product',
            'description' => '测试产品',
            'amount' => 100,
        ]]);

        $this->assertSame(0, EmailLog::query()->count());
        $this->assertSame(0, SmsLog::query()->count());
    }

    public function test_enabled_notification_policy_writes_logs(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSmtp();
        $this->installSms();

        app(InvoiceService::class)->generate($this->client(), [[
            'type' => 'product',
            'description' => '测试产品',
            'amount' => 100,
        ]]);

        $this->assertDatabaseHas('email_logs', ['template' => 'invoice_created', 'status' => 'sent']);
        $this->assertDatabaseHas('sms_logs', ['template' => 'invoice_created', 'status' => 'sent']);
    }

    public function test_admin_can_open_notification_center_and_template_pages(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.notifications.index'))
            ->assertOk();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.email-templates.index'))
            ->assertOk();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.sms-templates.index'))
            ->assertOk();
    }

    public function test_non_notification_admin_cannot_view_notification_logs_or_templates(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'notify-limited',
            'email' => 'notify-limited@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        $emailLog = EmailLog::query()->create([
            'to' => 'client@example.com',
            'subject' => '敏感邮件',
            'body' => '邮件内容',
            'status' => 'failed',
            'success' => false,
            'attempts' => 0,
        ]);
        $smsLog = SmsLog::query()->create([
            'phone' => '13800138100',
            'content' => '短信内容',
            'status' => 'failed',
            'success' => false,
            'attempts' => 0,
        ]);

        $this->actingAs($admin, 'admin')->get(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.email-logs.index'))->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.email-logs.show', $emailLog))->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.sms-logs.index'))->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.sms-logs.show', $smsLog))->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.email-templates.index'))->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.sms-templates.index'))->assertForbidden();
    }

    public function test_notification_center_recovers_from_legacy_collection_settings_cache(): void
    {
        $admin = $this->admin();
        app(SettingsService::class)->set('notify_invoice_created_mail', false, 'notification');
        Cache::forever('system:settings', collect(['notify_invoice_created_mail' => true]));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('关闭');

        $this->assertIsArray(Cache::get('system:settings'));
    }

    public function test_notification_center_falls_back_when_policy_setting_is_not_scalar(): void
    {
        $admin = $this->admin();
        \App\Models\Setting::query()->create([
            'key' => 'notify_invoice_created_mail',
            'value' => json_encode(['bad' => 'value']),
            'group' => 'notification',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('账单生成')
            ->assertSee('启用');
    }

    public function test_notification_center_hides_template_and_setting_links_without_permissions(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'notify-manage-only',
            'email' => 'notify-manage-only@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        $role = Role::query()->firstOrCreate(['name' => 'notification-manager', 'guard_name' => 'web']);
        $permission = Permission::query()->firstOrCreate(['name' => 'notification.manage', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $admin->syncRoles([$role]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('邮件日志')
            ->assertSee('短信日志')
            ->assertDontSee('邮件模板')
            ->assertDontSee('短信模板')
            ->assertDontSee('修改设置');
    }

    public function test_notifications_skip_inactive_and_deleted_clients(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSmtp();
        $this->installSms();
        $inactive = $this->client('notify-inactive', 'notify-inactive@example.com', '13800138101');
        $inactive->update(['status' => 2]);
        $deleted = $this->client('notify-deleted', 'notify-deleted@example.com', '13800138102');
        $deleted->delete();

        $inactiveResult = app(NotificationService::class)->notifyClient($inactive->fresh(), 'invoice_created', [
            'client_name' => $inactive->username,
            'invoice_number' => 'INV-INACTIVE',
            'amount' => 100,
        ]);
        $deletedResult = app(NotificationService::class)->notifyClient(Client::withTrashed()->findOrFail($deleted->id), 'invoice_created', [
            'client_name' => $deleted->username,
            'invoice_number' => 'INV-DELETED',
            'amount' => 100,
        ]);

        $this->assertSame('客户账号未启用或已删除，跳过通知。', $inactiveResult['errors']['client']);
        $this->assertSame('客户账号未启用或已删除，跳过通知。', $deletedResult['errors']['client']);
        $this->assertSame(0, EmailLog::query()->count());
        $this->assertSame(0, SmsLog::query()->count());
    }

    public function test_client_can_manage_notification_preferences_and_mandatory_notifications_remain_enabled(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->get(route('client.account.notifications'))
            ->assertOk()
            ->assertSee('通知偏好');

        $this->actingAs($client, 'client')
            ->post(route('client.account.notifications.update'), [
                'notifications' => [
                    'invoice_created' => 0,
                    'invoice_paid' => 0,
                    'password_changed' => 0,
                ],
            ])
            ->assertRedirect(route('client.account.notifications'))
            ->assertSessionHas('status', '通知偏好已更新');

        $client->refresh();
        $this->assertFalse((bool) $client->notification_preferences['invoice_created']);
        $this->assertFalse((bool) $client->notification_preferences['invoice_paid']);
        $this->assertTrue((bool) $client->notification_preferences['password_changed']);
    }

    public function test_closed_client_notification_preferences_prevent_delivery(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSmtp();
        $this->installSms();
        $client = $this->client();
        $client->update([
            'notification_preferences' => [
                'invoice_created' => false,
                'invoice_paid' => true,
                'password_changed' => true,
                'email_verification' => true,
                'account_locked' => true,
                'suspicious_login' => true,
                'host_due_reminder' => true,
                'invoice_receipt_submitted' => true,
                'invoice_receipt_issued' => true,
                'host_cancel_requested' => true,
                'host_cancel_approved' => true,
                'host_cancel_rejected' => true,
                'host_cancel_completed' => true,
                'host_renewal_invoice_created' => true,
                'host_upgrade_completed' => true,
                'ticket_replied' => true,
            ],
        ]);

        app(InvoiceService::class)->generate($client, [[
            'type' => 'product',
            'description' => '测试产品',
            'amount' => 100,
        ]]);

        $this->assertDatabaseMissing('email_logs', ['template' => 'invoice_created', 'to' => $client->email]);
        $this->assertDatabaseMissing('sms_logs', ['template' => 'invoice_created', 'phone' => $client->phone]);
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        Plugin::query()->where('name', 'smtp')->update(['config' => []]);
    }

    private function installSms(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('sms', 'aliyun');
        $manager->enable('aliyun');
        Plugin::query()->where('name', 'aliyun')->update(['config' => ['mock' => true]]);
    }

    private function client(
        string $username = 'notify-client',
        string $email = 'notify-client@example.com',
        string $phone = '13800138100'
    ): Client
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
            'phone_code' => '86',
            'phone' => $phone,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'admin-notify',
            'email' => 'admin-notify@example.com',
            'password' => Hash::make('admin123456'),
            'real_name' => '通知管理员',
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
