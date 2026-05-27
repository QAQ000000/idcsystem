<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\PaymentAttempt;
use App\Models\Plugin;
use App\Models\SmsLog;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\MarketplacePlugin;
use App\Modules\Admin\Services\PluginMarketplaceService;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Providers\PluginServiceProvider;
use App\Services\PluginConfigService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PluginTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Gateway/demo_route_plugin'));
        File::deleteDirectory(base_path('plugins/Gateway/demo_enabled_route_plugin'));
        File::deleteDirectory(base_path('plugins/Gateway/password_field_plugin'));
        File::deleteDirectory(base_path('plugins/Gateway/authorization_field_plugin'));
        File::deleteDirectory(base_path('plugins/Gateway/wrong_type_plugin'));
        File::deleteDirectory(base_path('plugins/Gateway/unsafe_manifest_name_plugin'));

        parent::tearDown();
    }

    public function test_plugin_config_service_saves_and_reads_config(): void
    {
        Plugin::query()->create([
            'name' => 'demo_pay',
            'title' => 'Demo Pay',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [],
        ]);

        $service = app(PluginConfigService::class);
        $service->save('demo_pay', ['app_id' => 'abc', 'endpoint' => 'https://pay.example.com']);

        $this->assertSame('abc', $service->get('demo_pay')['app_id']);
        $this->assertDatabaseHas('plugins', ['name' => 'demo_pay']);
    }

    public function test_plugin_config_service_filters_keys_not_declared_by_manifest_schema(): void
    {
        app(PluginManager::class)->install('gateway', 'manual_pay');

        $config = app(PluginConfigService::class)->save('manual_pay', [
            'instructions' => '请转账后提交工单',
            'bank_name' => 'IDC Bank',
            'pay_should_fail' => true,
            'unexpected_nested' => ['admin' => true],
        ])->config;

        $this->assertSame('请转账后提交工单', $config['instructions']);
        $this->assertSame('IDC Bank', $config['bank_name']);
        $this->assertArrayNotHasKey('pay_should_fail', $config);
        $this->assertArrayNotHasKey('unexpected_nested', $config);
    }

    public function test_plugin_config_service_casts_schema_boolean_and_number_values(): void
    {
        app(PluginManager::class)->install('server', 'mock_server');

        $config = app(PluginConfigService::class)->save('mock_server', [
            'fail_create' => '1',
            'fail_usage' => '0',
        ])->config;

        $this->assertTrue($config['fail_create']);
        $this->assertFalse($config['fail_usage']);
    }

    public function test_plugin_config_service_rejects_number_values_outside_manifest_range(): void
    {
        app(PluginManager::class)->install('email', 'smtp');

        $config = app(PluginConfigService::class)->save('smtp', [
            'host' => 'smtp.example.com',
            'port' => 70000,
        ])->config;

        $this->assertSame('smtp.example.com', $config['host']);
        $this->assertArrayNotHasKey('port', $config);

        $config = app(PluginConfigService::class)->save('smtp', [
            'port' => 587,
        ])->config;

        $this->assertSame(587, $config['port']);
    }

    public function test_plugin_config_service_rejects_array_values_for_scalar_schema_fields(): void
    {
        app(PluginManager::class)->install('gateway', 'manual_pay');

        $config = app(PluginConfigService::class)->save('manual_pay', [
            'instructions' => ['bad'],
            'bank_name' => 'IDC Bank',
            'account_number' => ['bad'],
        ])->config;

        $this->assertSame('IDC Bank', $config['bank_name']);
        $this->assertArrayNotHasKey('instructions', $config);
        $this->assertArrayNotHasKey('account_number', $config);
    }

    public function test_plugin_config_service_keeps_generic_fields_for_plugins_without_manifest_schema(): void
    {
        Plugin::query()->create([
            'name' => 'legacy_sms',
            'title' => 'Legacy SMS',
            'type' => 'sms',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [],
        ]);

        $config = app(PluginConfigService::class)->save('legacy_sms', [
            'app_id' => 'legacy-app',
            'endpoint' => 'https://sms.example.com',
            'unexpected' => 'drop-me',
        ])->config;

        $this->assertSame('legacy-app', $config['app_id']);
        $this->assertSame('https://sms.example.com', $config['endpoint']);
        $this->assertArrayNotHasKey('unexpected', $config);
    }

    public function test_plugin_config_service_keeps_existing_sensitive_values_when_blank(): void
    {
        Plugin::query()->create([
            'name' => 'secure_pay',
            'title' => 'Secure Pay',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [
                'app_id' => 'old-app',
                'app_secret' => 'old-secret',
                'access_key' => 'old-key',
            ],
        ]);

        $config = app(PluginConfigService::class)->save('secure_pay', [
            'app_id' => 'new-app',
            'app_secret' => '',
            'access_key' => null,
        ])->config;

        $this->assertSame('new-app', $config['app_id']);
        $this->assertSame('old-secret', $config['app_secret']);
        $this->assertSame('old-key', $config['access_key']);
    }

    public function test_plugin_config_save_invalidates_loaded_plugin_instance_cache(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        Plugin::query()->where('name', 'manual_pay')->update([
            'config' => ['instructions' => '旧说明'],
        ]);

        $this->assertSame('旧说明', $manager->get('manual_pay')->getConfig()['instructions']);

        app(PluginConfigService::class)->save('manual_pay', [
            'instructions' => '新说明',
        ]);

        $this->assertSame('新说明', $manager->get('manual_pay')->getConfig()['instructions']);
    }

    public function test_reinstalling_enabled_plugin_preserves_status_and_config(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        Plugin::query()->where('name', 'manual_pay')->update([
            'config' => ['instructions' => '保留说明'],
        ]);

        $this->assertTrue($manager->install('gateway', 'manual_pay'));

        $plugin = Plugin::query()->where('name', 'manual_pay')->firstOrFail();
        $this->assertSame(1, $plugin->status);
        $this->assertSame('保留说明', $plugin->config['instructions']);
    }

    public function test_plugin_install_rejects_same_name_with_different_existing_type(): void
    {
        Plugin::query()->create([
            'name' => 'manual_pay',
            'title' => 'Conflicting Manual Pay',
            'type' => 'email',
            'version' => '1.0.0',
            'status' => 0,
            'config' => [],
        ]);

        $this->assertFalse(app(PluginManager::class)->install('gateway', 'manual_pay'));
        $this->assertSame('email', Plugin::query()->where('name', 'manual_pay')->value('type'));
    }

    public function test_plugin_install_rejects_manifest_type_that_does_not_match_requested_directory(): void
    {
        $this->createManifestOnlyPlugin('wrong_type_plugin', [
            'name' => 'wrong_type_plugin',
            'title' => 'Wrong Type Plugin',
            'type' => 'email',
            'version' => '1.0.0',
            'entry' => 'MissingPlugin',
        ]);

        $manager = app(PluginManager::class);

        $this->assertFalse($manager->install('gateway', 'wrong_type_plugin'));
        $this->assertNull(collect($manager->scan('gateway'))->firstWhere('name', 'wrong_type_plugin'));
        $this->assertDatabaseMissing('plugins', ['name' => 'wrong_type_plugin']);
    }

    public function test_plugin_install_rejects_unsafe_manifest_name(): void
    {
        $this->createManifestOnlyPlugin('unsafe_manifest_name_plugin', [
            'name' => '../unsafe',
            'title' => 'Unsafe Manifest Name Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'entry' => 'MissingPlugin',
        ]);

        $manager = app(PluginManager::class);

        $this->assertFalse($manager->install('gateway', 'unsafe_manifest_name_plugin'));
        $this->assertEmpty($manager->manifest('gateway', '../unsafe'));
        $this->assertNull(collect($manager->scan('gateway'))->firstWhere('title', 'Unsafe Manifest Name Plugin'));
        $this->assertDatabaseMissing('plugins', ['title' => 'Unsafe Manifest Name Plugin']);
    }

    public function test_plugin_scan_installed_flag_is_scoped_by_type(): void
    {
        Plugin::query()->create([
            'name' => 'manual_pay',
            'title' => 'Conflicting Manual Pay',
            'type' => 'email',
            'version' => '1.0.0',
            'status' => 0,
            'config' => [],
        ]);

        $manualPay = collect(app(PluginManager::class)->scan('gateway'))->firstWhere('name', 'manual_pay');

        $this->assertNotNull($manualPay);
        $this->assertFalse($manualPay['installed']);
    }

    public function test_admin_can_browse_and_search_plugin_marketplace(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();

        MarketplacePlugin::query()->create([
            'name' => 'manual_pay',
            'title' => 'Manual Pay Market',
            'type' => 'gateway',
            'version' => '1.0.0',
            'description' => '银行转账收款',
            'downloads_count' => 8,
            'rating' => 4.5,
            'is_verified' => true,
        ]);
        MarketplacePlugin::query()->create([
            'name' => 'smtp',
            'title' => 'SMTP Mail Market',
            'type' => 'email',
            'version' => '1.0.0',
            'description' => '邮件发送服务',
            'downloads_count' => 3,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.index', ['search' => 'Manual', 'type' => 'gateway', 'verified' => 1]))
            ->assertOk()
            ->assertSee('Manual Pay Market')
            ->assertSee('已认证')
            ->assertDontSee('SMTP Mail Market');
    }

    public function test_marketplace_install_checks_requirements_and_installs_local_plugin(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $marketplacePlugin = MarketplacePlugin::query()->create([
            'name' => 'manual_pay',
            'title' => 'Manual Pay Market',
            'type' => 'gateway',
            'version' => '1.0.0',
            'description' => '银行转账收款',
            'requirements' => [
                'php' => '8.0',
                'laravel' => '10.0',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.marketplace.install', $marketplacePlugin))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('status', '市场插件已安装');

        $this->assertDatabaseHas('plugins', [
            'name' => 'manual_pay',
            'type' => 'gateway',
            'status' => 0,
        ]);
        $this->assertSame(1, $marketplacePlugin->fresh()->downloads_count);
    }

    public function test_marketplace_install_rejects_missing_requirement(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $marketplacePlugin = MarketplacePlugin::query()->create([
            'name' => 'manual_pay',
            'title' => 'Manual Pay Market',
            'type' => 'gateway',
            'version' => '1.0.0',
            'requirements' => ['php' => '99.0'],
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.marketplace.install', $marketplacePlugin))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('plugins', ['name' => 'manual_pay']);
        $this->assertSame(0, $marketplacePlugin->fresh()->downloads_count);
    }

    public function test_marketplace_service_can_uninstall_installed_plugin(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');

        $this->assertTrue(app(PluginMarketplaceService::class)->uninstall('manual_pay'));
        $this->assertDatabaseMissing('plugins', ['name' => 'manual_pay']);
    }

    public function test_admin_plugin_config_and_disable_flow(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $plugin = Plugin::query()->create([
            'name' => 'demo_sms',
            'title' => 'Demo SMS',
            'type' => 'sms',
            'version' => '1.0.0',
            'description' => '测试短信插件',
            'status' => 1,
            'config' => [],
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.config.save', $plugin->name), [
                'config' => [
                    'app_id' => 'sms-app',
                    'app_secret' => 'secret',
                    'endpoint' => 'https://sms.example.com',
                    'callback_url' => 'https://idc.example.com/callback',
                    'notes' => 'enabled',
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $this->assertSame('sms-app', $plugin->fresh()->config['app_id']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.disable', $plugin->name))
            ->assertRedirect(route('admin.plugins.index'));

        $this->assertSame(0, $plugin->fresh()->status);
    }

    public function test_admin_plugin_config_rejects_array_values_for_scalar_fields(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        app(PluginManager::class)->install('gateway', 'manual_pay');
        $plugin = Plugin::query()->where('name', 'manual_pay')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.config.save', $plugin->name), [
                'config' => [
                    'instructions' => ['bad'],
                    'bank_name' => 'IDC Bank',
                    'account_number' => ['bad'],
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $config = $plugin->fresh()->config;
        $this->assertSame('IDC Bank', $config['bank_name']);
        $this->assertArrayNotHasKey('instructions', $config);
        $this->assertArrayNotHasKey('account_number', $config);
    }

    public function test_admin_plugin_config_does_not_render_saved_secret_and_blank_secret_keeps_existing_value(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $plugin = Plugin::query()->create([
            'name' => 'secure_sms',
            'title' => 'Secure SMS',
            'type' => 'sms',
            'version' => '1.0.0',
            'description' => '安全配置测试',
            'status' => 1,
            'config' => [
                'app_id' => 'sms-app',
                'app_secret' => 'saved-secret',
                'endpoint' => 'https://sms.example.com',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.config', $plugin->name))
            ->assertOk()
            ->assertDontSee('saved-secret')
            ->assertSee('已保存，留空则不修改');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.config.save', $plugin->name), [
                'config' => [
                    'app_id' => 'sms-app-new',
                    'app_secret' => '',
                    'endpoint' => 'https://sms-new.example.com',
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $config = $plugin->fresh()->config;
        $this->assertSame('sms-app-new', $config['app_id']);
        $this->assertSame('saved-secret', $config['app_secret']);
        $this->assertSame('https://sms-new.example.com', $config['endpoint']);
    }

    public function test_admin_plugin_config_page_ignores_array_old_input_values(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $plugin = Plugin::query()->create([
            'name' => 'array_old_sms',
            'title' => 'Array Old SMS',
            'type' => 'sms',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [
                'app_id' => 'saved-app',
                'endpoint' => 'https://sms.example.com',
            ],
        ]);

        session()->flashInput([
            'config' => [
                'app_id' => ['polluted'],
                'endpoint' => ['polluted'],
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.config', $plugin->name))
            ->assertOk()
            ->assertSee('value="saved-app"', false)
            ->assertSee('value="https://sms.example.com"', false)
            ->assertDontSee('polluted');
    }

    public function test_password_type_config_field_is_treated_as_sensitive_even_when_key_name_is_generic(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $this->createPasswordFieldPlugin();
        $plugin = Plugin::query()->create([
            'name' => 'password_field_plugin',
            'title' => 'Password Field Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [
                'signature' => 'saved-signature',
                'merchant' => 'old-merchant',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.config', $plugin->name))
            ->assertOk()
            ->assertSee('已保存，留空则不修改')
            ->assertDontSee('saved-signature');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.config.save', $plugin->name), [
                'config' => [
                    'signature' => '',
                    'merchant' => 'new-merchant',
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $config = $plugin->fresh()->config;
        $this->assertSame('saved-signature', $config['signature']);
        $this->assertSame('new-merchant', $config['merchant']);
    }

    public function test_signature_config_key_is_treated_as_sensitive_even_when_declared_as_text(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $this->createTextSignaturePlugin();
        $plugin = Plugin::query()->create([
            'name' => 'text_signature_plugin',
            'title' => 'Text Signature Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [
                'signature' => 'saved-signature',
                'merchant' => 'old-merchant',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.config', $plugin->name))
            ->assertOk()
            ->assertSee('已保存，留空则不修改')
            ->assertDontSee('saved-signature');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.config.save', $plugin->name), [
                'config' => [
                    'signature' => '',
                    'merchant' => 'new-merchant',
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $config = $plugin->fresh()->config;
        $this->assertSame('saved-signature', $config['signature']);
        $this->assertSame('new-merchant', $config['merchant']);
    }

    public function test_authorization_config_key_is_treated_as_sensitive_even_when_declared_as_text(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();
        $this->createAuthorizationFieldPlugin();
        $plugin = Plugin::query()->create([
            'name' => 'authorization_field_plugin',
            'title' => 'Authorization Field Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [
                'authorization' => 'saved-authorization',
                'session_id' => 'saved-session',
                'merchant' => 'old-merchant',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.config', $plugin->name))
            ->assertOk()
            ->assertSee('已保存，留空则不修改')
            ->assertDontSee('saved-authorization')
            ->assertDontSee('saved-session');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.config.save', $plugin->name), [
                'config' => [
                    'authorization' => '',
                    'session_id' => '',
                    'merchant' => 'new-merchant',
                ],
            ])
            ->assertRedirect(route('admin.plugins.config', $plugin->name));

        $config = $plugin->fresh()->config;
        $this->assertSame('saved-authorization', $config['authorization']);
        $this->assertSame('saved-session', $config['session_id']);
        $this->assertSame('new-merchant', $config['merchant']);
    }

    public function test_non_super_admin_cannot_view_plugin_config_page(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'plugin-limited',
            'email' => 'plugin-limited@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        $plugin = Plugin::query()->create([
            'name' => 'sensitive_gateway',
            'title' => 'Sensitive Gateway',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 1,
            'config' => ['app_secret' => 'should-not-be-visible'],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.config', $plugin->name))
            ->assertForbidden();
    }

    public function test_admin_plugin_actions_report_failure_when_target_missing(): void
    {
        $this->seed();
        $admin = AdminUser::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.enable', 'missing-plugin'))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('error', '插件启用失败');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.disable', 'missing-plugin'))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('error', '插件禁用失败');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.uninstall', 'missing-plugin'))
            ->assertRedirect(route('admin.plugins.index'))
            ->assertSessionHas('error', '插件卸载失败');
    }

    public function test_plugin_enable_rejects_unloadable_plugin(): void
    {
        Plugin::query()->create([
            'name' => 'missing_gateway',
            'title' => 'Missing Gateway',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 0,
            'config' => [],
        ]);

        $this->assertFalse(app(PluginManager::class)->enable('missing_gateway'));
        $this->assertSame(0, Plugin::query()->where('name', 'missing_gateway')->value('status'));
    }

    public function test_non_super_admin_cannot_manage_plugins(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'plugin-admin',
            'email' => 'plugin-admin@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'plugin-manager', 'guard_name' => 'web']);
        $admin->syncRoles(['plugin-manager']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.plugins.enable', 'missing-plugin'))
            ->assertForbidden();
    }

    public function test_non_plugin_admin_cannot_view_plugin_index(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'plugin-index-limited',
            'email' => 'plugin-index-limited@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.plugins.index'))
            ->assertForbidden();
    }

    public function test_plugin_uninstall_rejects_business_references(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        $group = ProductGroup::query()->create(['name' => '服务器产品']);
        Product::query()->create([
            'group_id' => $group->id,
            'name' => '绑定服务器模块产品',
            'type' => 'vps',
            'server_type' => 'mock_server',
        ]);

        $this->assertFalse($manager->uninstall('mock_server'));
        $this->assertDatabaseHas('plugins', ['name' => 'mock_server']);

        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        $client = Client::query()->create([
            'username' => 'plugin-ref-client',
            'email' => 'plugin-ref-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
        Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => 1,
            'type' => 'credit',
            'amount' => 10,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'PLUGIN-REF-001',
            'refunded' => 0,
        ]);

        $this->assertFalse($manager->uninstall('manual_pay'));
        $this->assertDatabaseHas('plugins', ['name' => 'manual_pay']);
    }

    public function test_plugin_disable_rejects_business_references(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        $group = ProductGroup::query()->create(['name' => '服务器产品']);
        Product::query()->create([
            'group_id' => $group->id,
            'name' => '禁用保护服务器产品',
            'type' => 'vps',
            'server_type' => 'mock_server',
        ]);

        $this->assertFalse($manager->disable('mock_server'));
        $this->assertSame(1, Plugin::query()->where('name', 'mock_server')->value('status'));

        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        $client = Client::query()->create([
            'username' => 'plugin-disable-client',
            'email' => 'plugin-disable-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
        Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => 1,
            'type' => 'credit',
            'amount' => 10,
            'fee' => 0,
            'payment_method' => 'manual_pay',
            'gateway_trans_id' => 'PLUGIN-DISABLE-REF-001',
            'refunded' => 0,
        ]);

        $this->assertFalse($manager->disable('manual_pay'));
        $this->assertSame(1, Plugin::query()->where('name', 'manual_pay')->value('status'));
    }

    public function test_gateway_plugin_disable_and_uninstall_reject_unfinished_invoice_reference(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        $client = Client::query()->create([
            'username' => 'gateway-invoice-ref-client',
            'email' => 'gateway-invoice-ref-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
        Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-GATEWAY-REF',
            'subtotal' => 100,
            'total' => 100,
            'status' => 'Unpaid',
            'payment_method' => 'manual_pay',
        ]);

        $this->assertFalse($manager->disable('manual_pay'));
        $this->assertFalse($manager->uninstall('manual_pay'));
        $this->assertSame(1, Plugin::query()->where('name', 'manual_pay')->value('status'));
        $this->assertDatabaseHas('plugins', ['name' => 'manual_pay']);
    }

    public function test_gateway_plugin_disable_and_uninstall_reject_pending_payment_attempt_reference(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        $client = Client::query()->create([
            'username' => 'gateway-attempt-ref-client',
            'email' => 'gateway-attempt-ref-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-GATEWAY-ATTEMPT',
            'subtotal' => 100,
            'total' => 100,
            'status' => 'Unpaid',
        ]);
        PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'client_id' => $client->id,
            'gateway' => 'manual_pay',
            'amount' => 100,
            'status' => 'pending',
        ]);

        $this->assertFalse($manager->disable('manual_pay'));
        $this->assertFalse($manager->uninstall('manual_pay'));
        $this->assertSame(1, Plugin::query()->where('name', 'manual_pay')->value('status'));
        $this->assertDatabaseHas('plugins', ['name' => 'manual_pay']);
    }

    public function test_mail_and_sms_default_provider_references_block_disable_and_uninstall(): void
    {
        $manager = app(PluginManager::class);
        $settings = app(SettingsService::class);

        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        $settings->set('default_email_provider', 'smtp', 'mail');

        $this->assertFalse($manager->disable('smtp'));
        $this->assertFalse($manager->uninstall('smtp'));
        $this->assertSame(1, Plugin::query()->where('name', 'smtp')->value('status'));
        $this->assertDatabaseHas('plugins', ['name' => 'smtp']);

        $manager->install('sms', 'aliyun');
        $manager->enable('aliyun');
        $settings->set('default_sms_provider', 'aliyun', 'sms');

        $this->assertFalse($manager->disable('aliyun'));
        $this->assertFalse($manager->uninstall('aliyun'));
        $this->assertSame(1, Plugin::query()->where('name', 'aliyun')->value('status'));
        $this->assertDatabaseHas('plugins', ['name' => 'aliyun']);
    }

    public function test_notification_logs_block_provider_plugin_disable_and_uninstall_until_finished(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        EmailLog::query()->create([
            'to' => 'pending@example.com',
            'subject' => 'Pending',
            'body' => 'body',
            'provider' => 'smtp',
            'status' => 'pending',
            'success' => false,
            'payload' => [],
            'attempts' => 0,
        ]);

        $this->assertFalse($manager->disable('smtp'));
        $this->assertFalse($manager->uninstall('smtp'));
        $this->assertSame(1, Plugin::query()->where('name', 'smtp')->value('status'));

        EmailLog::query()->update(['status' => 'sent', 'success' => true, 'sent_at' => now()]);
        $this->assertTrue($manager->disable('smtp'));
        $this->assertSame(0, Plugin::query()->where('name', 'smtp')->value('status'));

        $manager->enable('smtp');
        $this->assertTrue($manager->uninstall('smtp'));
        $this->assertDatabaseMissing('plugins', ['name' => 'smtp']);

        $manager->install('sms', 'aliyun');
        $manager->enable('aliyun');
        SmsLog::query()->create([
            'phone' => '13800139999',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => 'processing',
            'provider' => 'aliyun',
            'status' => 'processing',
            'success' => false,
            'payload' => [],
            'attempts' => 0,
        ]);

        $this->assertFalse($manager->disable('aliyun'));
        $this->assertFalse($manager->uninstall('aliyun'));
        $this->assertSame(1, Plugin::query()->where('name', 'aliyun')->value('status'));

        SmsLog::query()->update(['status' => 'failed']);
        $this->assertFalse($manager->disable('aliyun'));

        SmsLog::query()->update(['status' => 'sent', 'success' => true, 'sent_at' => now()]);
        $this->assertTrue($manager->disable('aliyun'));
        $this->assertSame(0, Plugin::query()->where('name', 'aliyun')->value('status'));
    }

    public function test_plugin_disable_and_uninstall_ignore_missing_optional_reference_tables(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');

        Schema::dropIfExists('email_logs');

        $this->assertTrue($manager->disable('smtp'));
        $this->assertSame(0, Plugin::query()->where('name', 'smtp')->value('status'));

        $manager->enable('smtp');
        $this->assertTrue($manager->uninstall('smtp'));
        $this->assertDatabaseMissing('plugins', ['name' => 'smtp']);
    }

    public function test_disabled_plugin_route_file_is_not_registered(): void
    {
        $this->createRoutedPlugin('demo_route_plugin');
        Plugin::query()->create([
            'name' => 'demo_route_plugin',
            'title' => 'Demo Route Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 0,
            'config' => [],
        ]);

        $this->bootPluginRoutesForTest();

        $this->get('/__plugin-test/demo_route_plugin')->assertNotFound();
    }

    public function test_enabled_plugin_route_file_is_registered(): void
    {
        $this->createRoutedPlugin('demo_enabled_route_plugin');
        Plugin::query()->create([
            'name' => 'demo_enabled_route_plugin',
            'title' => 'Demo Enabled Route Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'status' => 1,
            'config' => [],
        ]);

        $this->bootPluginRoutesForTest();

        $this->get('/__plugin-test/demo_enabled_route_plugin')
            ->assertOk()
            ->assertSee('demo_enabled_route_plugin');
    }

    private function createRoutedPlugin(string $name): void
    {
        $pluginPath = base_path('plugins/Gateway/'.$name);

        if (! is_dir($pluginPath.'/routes')) {
            mkdir($pluginPath.'/routes', 0777, true);
        }

        file_put_contents($pluginPath.'/plugin.json', json_encode([
            'name' => $name,
            'title' => $name,
            'type' => 'gateway',
            'version' => '1.0.0',
            'entry' => 'MissingPlugin',
        ], JSON_PRETTY_PRINT));

        file_put_contents($pluginPath.'/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/__plugin-test/{name}', fn (string $name) => $name)->name('plugins.demo_route_plugin.test');
PHP);

        $routeFile = $pluginPath.'/routes/web.php';
        $contents = file_get_contents($routeFile);
        file_put_contents($routeFile, str_replace('plugins.demo_route_plugin.test', "plugins.{$name}.test", $contents));
    }

    private function createManifestOnlyPlugin(string $directory, array $manifest): void
    {
        $pluginPath = base_path('plugins/Gateway/'.$directory);

        if (! is_dir($pluginPath)) {
            mkdir($pluginPath, 0777, true);
        }

        file_put_contents($pluginPath.'/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function createPasswordFieldPlugin(): void
    {
        $pluginPath = base_path('plugins/Gateway/password_field_plugin');

        if (! is_dir($pluginPath)) {
            mkdir($pluginPath, 0777, true);
        }

        file_put_contents($pluginPath.'/plugin.json', json_encode([
            'name' => 'password_field_plugin',
            'title' => 'Password Field Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'entry' => 'MissingPlugin',
            'config_schema' => [
                ['key' => 'signature', 'label' => 'Signature', 'type' => 'password'],
                ['key' => 'merchant', 'label' => 'Merchant', 'type' => 'text'],
            ],
        ], JSON_PRETTY_PRINT));
    }

    private function createTextSignaturePlugin(): void
    {
        $pluginPath = base_path('plugins/Gateway/text_signature_plugin');

        if (! is_dir($pluginPath)) {
            mkdir($pluginPath, 0777, true);
        }

        file_put_contents($pluginPath.'/plugin.json', json_encode([
            'name' => 'text_signature_plugin',
            'title' => 'Text Signature Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'entry' => 'MissingPlugin',
            'config_schema' => [
                ['key' => 'signature', 'label' => 'Signature', 'type' => 'text'],
                ['key' => 'merchant', 'label' => 'Merchant', 'type' => 'text'],
            ],
        ], JSON_PRETTY_PRINT));
    }

    private function createAuthorizationFieldPlugin(): void
    {
        $pluginPath = base_path('plugins/Gateway/authorization_field_plugin');

        if (! is_dir($pluginPath)) {
            mkdir($pluginPath, 0777, true);
        }

        file_put_contents($pluginPath.'/plugin.json', json_encode([
            'name' => 'authorization_field_plugin',
            'title' => 'Authorization Field Plugin',
            'type' => 'gateway',
            'version' => '1.0.0',
            'entry' => 'MissingPlugin',
            'config_schema' => [
                ['key' => 'authorization', 'label' => 'Authorization', 'type' => 'text'],
                ['key' => 'session_id', 'label' => 'Session ID', 'type' => 'text'],
                ['key' => 'merchant', 'label' => 'Merchant', 'type' => 'text'],
            ],
        ], JSON_PRETTY_PRINT));
    }

    private function bootPluginRoutesForTest(): void
    {
        $provider = new class($this->app) extends PluginServiceProvider
        {
            public function bootRoutes(): void
            {
                $this->loadPluginRoutes();
            }
        };

        $provider->bootRoutes();
    }
}
