<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\EmailLog;
use App\Models\LoginAttempt;
use App\Modules\Admin\Models\AdminUser;
use App\Services\LogCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_cleanup_service_deletes_expired_registered_tables(): void
    {
        config(['logging.retention.email_logs' => 1]);
        $old = EmailLog::query()->create([
            'to' => 'old@example.com',
            'subject' => 'old',
            'template' => 'demo',
            'status' => 'sent',
            'success' => true,
        ]);
        DB::table('email_logs')->where('id', $old->id)->update([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);
        EmailLog::query()->create([
            'to' => 'new@example.com',
            'subject' => 'new',
            'template' => 'demo',
            'status' => 'sent',
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(LogCleanupService::class)->cleanup();

        $this->assertSame(1, $result['email_logs']);
        $this->assertDatabaseMissing('email_logs', ['to' => 'old@example.com']);
        $this->assertDatabaseHas('email_logs', ['to' => 'new@example.com']);
    }

    public function test_logs_cleanup_command_writes_system_task_log(): void
    {
        $this->artisan('logs:cleanup')->assertExitCode(0);

        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'logs:cleanup',
            'status' => 'success',
        ]);
    }

    public function test_admin_can_view_log_summaries_and_search_email_logs(): void
    {
        $admin = $this->admin(['log.view']);
        EmailLog::query()->create([
            'to' => 'needle@example.com',
            'subject' => 'Search Needle',
            'template' => 'demo',
            'status' => 'failed',
            'success' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        EmailLog::query()->create([
            'to' => 'other@example.com',
            'subject' => 'Other',
            'template' => 'demo',
            'status' => 'sent',
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.logs.index'))
            ->assertOk()
            ->assertSee('email_logs');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.logs.show', ['type' => 'email_logs', 'q' => 'needle', 'status' => 'failed']))
            ->assertOk()
            ->assertSee('needle@example.com')
            ->assertDontSee('other@example.com');
    }

    public function test_admin_can_search_login_attempts_and_admin_actions(): void
    {
        $admin = $this->admin(['log.view']);
        LoginAttempt::query()->create([
            'email' => 'login-search@example.com',
            'ip' => '127.0.0.1',
            'status' => 'failed',
            'failure_reason' => 'invalid_password',
            'created_at' => now(),
        ]);
        AdminActionLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => 'log.search.demo',
            'result' => 'success',
            'payload' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.logs.show', ['type' => 'login_attempts', 'q' => 'login-search']))
            ->assertOk()
            ->assertSee('login-search@example.com');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.logs.show', ['type' => 'admin_action_logs', 'q' => 'log.search']))
            ->assertOk()
            ->assertSee('log.search.demo');
    }

    public function test_admin_can_trigger_manual_log_cleanup(): void
    {
        $admin = $this->admin(['log.view', 'log.manage']);
        config(['logging.retention.email_logs' => 1]);
        $old = EmailLog::query()->create([
            'to' => 'cleanup@example.com',
            'subject' => 'cleanup',
            'template' => 'demo',
            'status' => 'sent',
            'success' => true,
        ]);
        DB::table('email_logs')->where('id', $old->id)->update([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logs.cleanup'))
            ->assertRedirect(route('admin.logs.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('email_logs', ['to' => 'cleanup@example.com']);
    }

    private function admin(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'log-admin-' . random_int(1000, 9999),
            'email' => 'log-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'log-admin', 'guard_name' => 'web']);
        $admin->syncRoles([$role]);
        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }
}
