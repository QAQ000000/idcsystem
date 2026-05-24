<?php

namespace Tests\Feature;

use App\Models\SystemTaskLog;
use App\Modules\Admin\Models\AdminUser;
use App\Services\SystemTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_task_service_writes_success_log(): void
    {
        $log = app(SystemTaskService::class)->run('demo:success', fn () => ['ok' => true]);

        $this->assertSame('success', $log->status);
        $this->assertNotNull($log->finished_at);
        $this->assertSame('{"ok":true}', $log->output);
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'demo:success',
            'status' => 'success',
        ]);
    }

    public function test_system_task_service_writes_failed_log(): void
    {
        $log = app(SystemTaskService::class)->run('demo:failed', function () {
            throw new \RuntimeException('任务失败');
        });

        $this->assertSame('failed', $log->status);
        $this->assertSame('任务失败', $log->error);
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'demo:failed',
            'status' => 'failed',
            'error' => '任务失败',
        ]);
    }

    public function test_host_usage_command_writes_system_task_log(): void
    {
        $this->artisan('host:sync-usage')->assertExitCode(0);

        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'host:sync-usage',
            'status' => 'success',
        ]);
    }

    public function test_admin_can_view_system_task_logs(): void
    {
        SystemTaskLog::query()->create([
            'task_name' => 'host:sync-usage',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 10,
            'output' => 'processed 0 hosts',
            'error' => '测试错误',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.system-tasks.index'))
            ->assertOk()
            ->assertSee('host:sync-usage')
            ->assertSee('测试错误');
    }

    public function test_scheduler_contains_host_tasks(): void
    {
        $this->artisan('schedule:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('host:sync-usage')
            ->expectsOutputToContain('host:send-due-reminders');
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->create([
            'username' => 'admin-task-' . random_int(1000, 9999),
            'email' => 'admin-task-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
    }
}
