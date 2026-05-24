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
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
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

    private function client(): Client
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'notify-client',
            'email' => 'notify-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => 1,
            'phone_code' => '86',
            'phone' => '13800138100',
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
