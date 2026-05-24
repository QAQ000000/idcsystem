<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\PluginConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginTest extends TestCase
{
    use RefreshDatabase;

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
}
