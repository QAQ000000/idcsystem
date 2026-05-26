<?php

namespace Tests\Feature;

use App\Models\LoginAttempt;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_client_login_is_recorded(): void
    {
        $client = $this->client();

        $this->post(route('client.login.store'), [
            'email' => strtoupper($client->email),
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('login_attempts', [
            'email' => $client->email,
            'ip' => '127.0.0.1',
            'status' => 'failed',
            'failure_reason' => 'invalid_password',
        ]);
    }

    public function test_five_failed_logins_lock_client_account(): void
    {
        $client = $this->client();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('client.login.store'), [
                'email' => $client->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $client->refresh();
        $this->assertNotNull($client->locked_until);
        $this->assertTrue($client->locked_until->isFuture());
        $this->assertSame(5, LoginAttempt::query()
            ->where('email', $client->email)
            ->where('status', 'failed')
            ->where('failure_reason', 'invalid_password')
            ->count());

        $this->post(route('client.login.store'), [
            'email' => $client->email,
            'password' => 'client123456',
        ])->assertSessionHasErrors(['email' => '账户已被锁定，请稍后再试。']);

        $this->assertGuest('client');
        $this->assertDatabaseMissing('client_login_logs', ['client_id' => $client->id]);
    }

    public function test_successful_login_records_success_attempt(): void
    {
        $client = $this->client();

        $this->post(route('client.login.store'), [
            'email' => $client->email,
            'password' => 'client123456',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertDatabaseHas('login_attempts', [
            'email' => $client->email,
            'ip' => '127.0.0.1',
            'status' => 'success',
            'failure_reason' => null,
        ]);
        $this->assertDatabaseHas('client_login_logs', ['client_id' => $client->id]);
    }

    public function test_admin_can_filter_login_attempts(): void
    {
        $admin = $this->admin();
        LoginAttempt::query()->create([
            'email' => 'target@example.com',
            'ip' => '192.0.2.10',
            'user_agent' => 'Target Agent',
            'status' => 'failed',
            'failure_reason' => 'invalid_password',
            'created_at' => now(),
        ]);
        LoginAttempt::query()->create([
            'email' => 'other@example.com',
            'ip' => '198.51.100.20',
            'user_agent' => 'Other Agent',
            'status' => 'success',
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.login-attempts.index', [
                'email' => 'target',
                'ip' => '192.0.2',
                'status' => 'failed',
            ]))
            ->assertOk()
            ->assertSee('target@example.com')
            ->assertSee('invalid_password')
            ->assertDontSee('other@example.com');
    }

    public function test_admin_can_unlock_client_account(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $client->update(['locked_until' => now()->addHour()]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.unlock', $client))
            ->assertRedirect(route('admin.clients.show', $client))
            ->assertSessionHas('status', '账户已解锁');

        $this->assertNull($client->fresh()->locked_until);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'client.unlock',
            'target_id' => $client->id,
            'result' => 'success',
        ]);
    }

    private function client(string $username = 'login-security', string $email = 'login-security@example.com'): Client
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
            'phone' => '13800138010',
        ]);
    }

    private function admin(): AdminUser
    {
        $this->seed();
        Permission::query()->firstOrCreate(['name' => 'login_attempt.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return AdminUser::query()->where('username', 'admin')->firstOrFail();
    }
}
