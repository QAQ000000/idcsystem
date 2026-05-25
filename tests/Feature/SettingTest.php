<?php

namespace Tests\Feature;

use App\Services\SettingsService;
use App\Services\ThemeService;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_service_saves_reads_defaults_and_clears_cache(): void
    {
        $settings = app(SettingsService::class);

        $this->assertSame('fallback', $settings->get('missing_key', 'fallback'));

        $settings->set('site_name', 'IDC Demo', 'general');
        $settings->set('invoice_due_days', 9, 'order');
        $settings->set('maintenance_mode', true, 'general');

        $this->assertSame('IDC Demo', $settings->get('site_name'));
        $this->assertSame(9, $settings->get('invoice_due_days'));
        $this->assertSame(1, $settings->get('maintenance_mode'));
        $this->assertTrue($settings->all()->has('site_name'));
        $this->assertTrue($settings->all('order')->has('invoice_due_days'));
    }

    public function test_admin_settings_page_can_save_core_groups(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.update'), [
                'site_name' => 'IDC Cloud',
                'site_url' => 'https://idc.example.com',
                'default_currency' => 'CNY',
                'theme' => 'minimal',
                'maintenance_mode' => '1',
                'auto_setup_policy' => 'paid',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 5,
                'mail_from_name' => 'IDC Cloud',
                'mail_from_address' => 'notice@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'smtp-user',
                'smtp_password' => 'smtp-pass',
                'sms_provider' => 'demo-sms',
                'sms_signature' => 'IDC',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertDatabaseHas('settings', ['key' => 'site_name', 'value' => 'IDC Cloud', 'group' => 'general']);
        $this->assertDatabaseHas('settings', ['key' => 'theme', 'value' => 'minimal', 'group' => 'general']);
        $this->assertDatabaseHas('settings', ['key' => 'auto_setup_policy', 'value' => 'paid', 'group' => 'order']);
        $this->assertDatabaseHas('settings', ['key' => 'billing_due_days', 'value' => '7', 'group' => 'billing']);
        $this->assertDatabaseHas('settings', ['key' => 'billing_reminder_days', 'value' => '5', 'group' => 'billing']);
        $this->assertDatabaseHas('settings', ['key' => 'sms_signature', 'value' => 'IDC', 'group' => 'sms']);
    }

    public function test_theme_service_lists_available_themes_and_falls_back_to_default(): void
    {
        $themes = app(ThemeService::class);

        $this->assertContains('default', $themes->available());
        $this->assertContains('minimal', $themes->available());

        app(SettingsService::class)->set('theme', 'missing-theme', 'general');
        $this->assertSame('default', $themes->active());

        app(SettingsService::class)->set('theme', 'minimal', 'general');
        $this->assertSame('minimal', $themes->active());
    }

    public function test_billing_config_prefers_saved_settings(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('billing_tax_rate', 6.5, 'billing');
        $settings->set('billing_due_days', 12, 'billing');
        $settings->set('billing_grace_days', 3, 'billing');
        $settings->set('billing_invoice_days_before_due', 9, 'billing');

        $billing = require config_path('billing.php');

        $this->assertSame(6.5, $billing['tax_rate']);
        $this->assertSame(12, $billing['due_days']);
        $this->assertSame(3, $billing['grace_days']);
        $this->assertSame(9, $billing['invoice_days_before_due']);
    }

    public function test_admin_settings_page_rejects_invalid_enum_values(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.settings.index'))
            ->post(route('admin.settings.update'), [
                'site_name' => 'IDC Cloud',
                'site_url' => 'https://idc.example.com',
                'default_currency' => 'CNY',
                'auto_setup_policy' => 'broken-policy',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 5,
                'mail_from_name' => 'IDC Cloud',
                'mail_from_address' => 'notice@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'smtp-user',
                'smtp_encryption' => 'starttls',
                'default_email_provider' => 'smtp',
                'default_sms_provider' => 'aliyun',
                'sms_signature' => 'IDC',
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors(['auto_setup_policy', 'smtp_encryption']);

        $this->assertDatabaseMissing('settings', ['key' => 'auto_setup_policy', 'value' => 'broken-policy']);
        $this->assertDatabaseMissing('settings', ['key' => 'smtp_encryption', 'value' => 'starttls']);
    }

    public function test_admin_settings_page_rejects_unknown_default_currency(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.settings.index'))
            ->post(route('admin.settings.update'), [
                'site_name' => 'IDC Cloud',
                'site_url' => 'https://idc.example.com',
                'default_currency' => 'BAD',
                'auto_setup_policy' => 'paid',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 5,
                'mail_from_name' => 'IDC Cloud',
                'mail_from_address' => 'notice@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'smtp-user',
                'default_email_provider' => 'smtp',
                'default_sms_provider' => 'aliyun',
                'sms_signature' => 'IDC',
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors('default_currency');

        $this->assertDatabaseMissing('settings', ['key' => 'default_currency', 'value' => 'BAD']);
    }

    public function test_admin_settings_page_syncs_default_currency_flag(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.update'), [
                'site_name' => 'IDC Cloud',
                'site_url' => 'https://idc.example.com',
                'default_currency' => 'USD',
                'auto_setup_policy' => 'paid',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 5,
                'mail_from_name' => 'IDC Cloud',
                'mail_from_address' => 'notice@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'smtp-user',
                'default_email_provider' => 'smtp',
                'default_sms_provider' => 'aliyun',
                'sms_signature' => 'IDC',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertTrue($usd->fresh()->is_default);
        $this->assertFalse((bool) Currency::query()->where('code', 'CNY')->value('is_default'));
        $this->assertSame($usd->id, app(PricingService::class)->defaultCurrencyId());
    }

    public function test_admin_settings_page_keeps_existing_smtp_password_when_blank(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        app(SettingsService::class)->set('smtp_password', 'old-smtp-pass', 'mail');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.update'), [
                'site_name' => 'IDC Cloud',
                'site_url' => 'https://idc.example.com',
                'default_currency' => 'CNY',
                'maintenance_mode' => '0',
                'auto_setup_policy' => 'paid',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 5,
                'mail_from_name' => 'IDC Cloud',
                'mail_from_address' => 'notice@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'smtp-user',
                'smtp_password' => '',
                'default_email_provider' => 'smtp',
                'default_sms_provider' => 'aliyun',
                'sms_signature' => 'IDC',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame('old-smtp-pass', app(SettingsService::class)->get('smtp_password'));
    }

    public function test_admin_settings_page_overwrites_existing_smtp_password_when_provided(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        app(SettingsService::class)->set('smtp_password', 'old-smtp-pass', 'mail');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.update'), [
                'site_name' => 'IDC Cloud',
                'site_url' => 'https://idc.example.com',
                'default_currency' => 'CNY',
                'maintenance_mode' => '0',
                'auto_setup_policy' => 'paid',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 5,
                'mail_from_name' => 'IDC Cloud',
                'mail_from_address' => 'notice@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'smtp-user',
                'smtp_password' => 'new-smtp-pass',
                'default_email_provider' => 'smtp',
                'default_sms_provider' => 'aliyun',
                'sms_signature' => 'IDC',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame('new-smtp-pass', app(SettingsService::class)->get('smtp_password'));
    }

    public function test_admin_settings_page_can_disable_notification_policies(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.update'), [
                'site_name' => 'IDC Cloud',
                'site_url' => 'https://idc.example.com',
                'default_currency' => 'CNY',
                'auto_setup_policy' => 'paid',
                'invoice_due_days' => 7,
                'renewal_reminder_days' => 5,
                'mail_from_name' => 'IDC Cloud',
                'mail_from_address' => 'notice@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'smtp-user',
                'default_email_provider' => 'smtp',
                'default_sms_provider' => 'aliyun',
                'sms_signature' => 'IDC',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $settings = app(SettingsService::class);

        $this->assertSame(0, $settings->get('notify_invoice_created_mail'));
        $this->assertSame(0, $settings->get('notify_invoice_created_sms'));
        $this->assertSame(0, $settings->get('notify_invoice_paid_mail'));
        $this->assertSame(0, $settings->get('notify_invoice_paid_sms'));
        $this->assertSame(0, $settings->get('notify_ticket_replied_mail'));
        $this->assertSame(0, $settings->get('notify_ticket_replied_sms'));
        $this->assertSame(0, $settings->get('notify_password_changed_mail'));
        $this->assertSame(0, $settings->get('notify_host_due_reminder_sms'));
    }

    public function test_admin_settings_page_lists_all_notification_events(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('密码修改')
            ->assertSee('续费账单生成')
            ->assertSee('服务到期提醒')
            ->assertSee('升级/降配完成');
    }

    public function test_admin_settings_page_reads_array_backed_settings_cache(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        app(SettingsService::class)->set('site_name', '缓存测试站点');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('缓存测试站点');
    }

    public function test_admin_settings_page_uses_setting_service_defaults_when_values_are_missing(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee((string) config('app.name'))
            ->assertSee((string) config('app.url'))
            ->assertSee('IDC System')
            ->assertSee('hello@example.com');
    }

    public function test_admin_settings_page_recovers_from_legacy_collection_settings_cache(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        app(SettingsService::class)->set('site_name', '旧缓存恢复站点');
        Cache::forever('system:settings', collect(['site_name' => '旧对象缓存']));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('旧缓存恢复站点');

        $this->assertIsArray(Cache::get('system:settings'));
    }

    public function test_admin_settings_page_recovers_from_nested_invalid_settings_cache_values(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        app(SettingsService::class)->set('site_name', '缓存污染恢复站点');
        Cache::forever('system:settings', ['site_name' => new \stdClass()]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('缓存污染恢复站点');

        $this->assertSame('缓存污染恢复站点', Cache::get('system:settings')['site_name']);
    }

    public function test_settings_service_get_falls_back_when_decoded_value_is_not_scalar(): void
    {
        \App\Models\Setting::query()->create([
            'key' => 'site_name',
            'value' => json_encode(['bad' => 'value']),
            'group' => 'general',
        ]);

        $this->assertSame('默认站点', app(SettingsService::class)->get('site_name', '默认站点'));
    }

    public function test_admin_settings_page_ignores_array_old_input_values(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->withSession([
            '_old_input' => [
                'site_name' => ['bad' => 'value'],
                'smtp_host' => ['bad' => 'value'],
                'notify_invoice_created_mail' => ['bad' => 'value'],
            ],
        ])
            ->actingAs($admin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee((string) config('app.name'));
    }

    public function test_non_setting_admin_cannot_view_settings_page(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'setting-limited-' . random_int(1000, 9999),
            'email' => 'setting-limited-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }
}
