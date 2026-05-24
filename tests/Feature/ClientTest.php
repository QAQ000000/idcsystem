<?php

namespace Tests\Feature;

use App\Models\ClientLoginLog;
use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_view_and_update_profile(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->get(route('client.account.profile'))
            ->assertOk();

        $this->actingAs($client, 'client')
            ->put(route('client.account.profile.update'), [
                'username' => 'client-updated',
                'company_name' => 'IDC Co',
                'phone_code' => '86',
                'phone' => '13800138011',
                'country' => '中国',
                'province' => '广东',
                'city' => '深圳',
                'address' => '科技园',
            ])
            ->assertRedirect(route('client.account.profile'));

        $this->assertSame('client-updated', $client->fresh()->username);
    }

    public function test_client_cannot_change_password_with_wrong_current_password(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->put(route('client.account.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_client_can_change_password_and_receive_notification(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSmtp();
        $this->installSms();
        $client = $this->client();
        app(SettingsService::class)->set('notify_password_changed_mail', true, 'notification');
        app(SettingsService::class)->set('notify_password_changed_sms', true, 'notification');

        $this->actingAs($client, 'client')
            ->put(route('client.account.password.update'), [
                'current_password' => 'client123456',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertRedirect(route('client.account.security'));

        $this->assertTrue(Hash::check('newpassword123', $client->fresh()->password));
        $this->assertDatabaseHas('email_logs', ['template' => 'password_changed']);
        $this->assertDatabaseHas('sms_logs', ['template' => 'password_changed']);
    }

    public function test_client_login_creates_login_logs(): void
    {
        $client = $this->client();
        $this->post(route('client.login.store'), [
            'email' => $client->email,
            'password' => 'client123456',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertDatabaseHas('client_login_logs', ['client_id' => $client->id]);
    }

    public function test_admin_client_show_page_displays_enhanced_information(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        ClientLoginLog::query()->create([
            'client_id' => $client->id,
            'ip' => '127.0.0.1',
            'user_agent' => 'Test Agent',
            'logged_in_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.clients.show', $client))
            ->assertOk();
    }

    private function client(): Client
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'client-security',
            'email' => 'client-security@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => 1,
            'phone_code' => '86',
            'phone' => '13800138010',
        ]);
    }

    private function admin(): AdminUser
    {
        $this->seed();

        return AdminUser::query()->where('username', 'admin')->firstOrFail();
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
    }

    private function installSms(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('sms', 'aliyun');
        $manager->enable('aliyun');
        \App\Models\Plugin::query()->where('name', 'aliyun')->update(['config' => ['mock' => true]]);
    }
}
