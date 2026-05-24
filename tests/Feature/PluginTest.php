<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Account;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\PluginConfigService;
use App\Services\SettingsService;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PluginTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Gateway/demo_route_plugin'));
        File::deleteDirectory(base_path('plugins/Gateway/demo_enabled_route_plugin'));

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

    public function test_admin_plugin_config_and_disable_flow(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();
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

    public function test_admin_plugin_config_does_not_render_saved_secret_and_blank_secret_keeps_existing_value(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();
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

    public function test_admin_plugin_actions_report_failure_when_target_missing(): void
    {
        $this->seed();
        $admin = \App\Modules\Admin\Models\AdminUser::query()->where('username', 'admin')->firstOrFail();

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
        $pluginPath = base_path('plugins/Gateway/' . $name);

        if (!is_dir($pluginPath . '/routes')) {
            mkdir($pluginPath . '/routes', 0777, true);
        }

        file_put_contents($pluginPath . '/plugin.json', json_encode([
            'name' => $name,
            'title' => $name,
            'type' => 'gateway',
            'version' => '1.0.0',
            'entry' => 'MissingPlugin',
        ], JSON_PRETTY_PRINT));

        file_put_contents($pluginPath . '/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/__plugin-test/{name}', fn (string $name) => $name)->name('plugins.demo_route_plugin.test');
PHP);

        $routeFile = $pluginPath . '/routes/web.php';
        $contents = file_get_contents($routeFile);
        file_put_contents($routeFile, str_replace('plugins.demo_route_plugin.test', "plugins.{$name}.test", $contents));
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
}
