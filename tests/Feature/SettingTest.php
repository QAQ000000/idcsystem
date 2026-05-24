<?php

namespace Tests\Feature;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        $this->assertDatabaseHas('settings', ['key' => 'auto_setup_policy', 'value' => 'paid', 'group' => 'order']);
        $this->assertDatabaseHas('settings', ['key' => 'sms_signature', 'value' => 'IDC', 'group' => 'sms']);
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
}
