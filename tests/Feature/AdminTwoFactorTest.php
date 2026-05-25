<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\User\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enable_two_factor_and_must_complete_otp_on_login(): void
    {
        $admin = $this->admin();
        $twoFactor = app(TwoFactorService::class);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.profile.2fa'))
            ->assertOk()
            ->assertSee('手动密钥');

        $secret = session('admin_2fa_setup_secret');
        $this->assertIsString($secret);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.profile.2fa.enable'), [
                'code' => $twoFactor->currentCode($secret),
            ])
            ->assertRedirect(route('admin.profile.2fa'))
            ->assertSessionHas('status', '管理员两步验证已启用。');

        $admin->refresh();
        $this->assertTrue($admin->two_factor_enabled);
        $this->assertSame($secret, $admin->two_factor_secret);

        auth('admin')->logout();

        $this->post(route('admin.login.store'), [
            'username' => $admin->email,
            'password' => 'admin123456',
        ])
            ->assertRedirect(route('admin.login.2fa'));

        $this->assertGuest('admin');

        $this->post(route('admin.login.2fa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->post(route('admin.login.2fa.verify'), [
            'code' => $twoFactor->currentCode($secret),
        ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_can_disable_two_factor_with_password(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.profile.2fa.disable'), [
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($admin->fresh()->two_factor_enabled);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.profile.2fa.disable'), [
                'password' => 'admin123456',
            ])
            ->assertRedirect(route('admin.profile.2fa'))
            ->assertSessionHas('status', '管理员两步验证已关闭。');

        $admin->refresh();
        $this->assertFalse($admin->two_factor_enabled);
        $this->assertNull($admin->two_factor_secret);
    }

    private function admin(array $overrides = []): AdminUser
    {
        $admin = AdminUser::query()->create(array_merge([
            'username' => 'admin-2fa',
            'email' => 'admin-2fa@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ], $overrides));

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
