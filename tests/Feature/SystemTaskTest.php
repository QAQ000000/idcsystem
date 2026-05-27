<?php

namespace Tests\Feature;

use App\Models\SystemTaskLog;
use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Modules\Admin\Models\AdminUser;
use App\Services\SystemTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
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

    public function test_system_task_service_marks_partial_failures_as_failed(): void
    {
        $log = app(SystemTaskService::class)->run('demo:partial-failed', fn () => [
            'processed' => 2,
            'success' => 1,
            'failed' => 1,
        ]);

        $this->assertSame('failed', $log->status);
        $this->assertSame('1 个子任务失败', $log->error);
        $this->assertSame('{"processed":2,"success":1,"failed":1}', $log->output);
    }

    public function test_system_task_service_marks_error_result_keys_as_failed(): void
    {
        $log = app(SystemTaskService::class)->run('demo:error-result', fn () => [
            'processed' => 1,
            'success' => 1,
            'errors' => [
                'provider' => 'failed password=plain-secret token:token-value',
            ],
        ]);

        $this->assertSame('failed', $log->status);
        $this->assertSame('{"provider":"failed password=[FILTERED] token:[FILTERED]"}', $log->error);
        $this->assertStringNotContainsString('plain-secret', (string) $log->error);
        $this->assertStringNotContainsString('token-value', (string) $log->error);
    }

    public function test_system_task_service_masks_sensitive_output_values(): void
    {
        $log = app(SystemTaskService::class)->run('demo:sensitive-output', fn () => [
            'processed' => 1,
            'db_password' => 'plain-secret',
            'nested' => [
                'access_token' => 'token-value',
                'api_key' => 'key-value',
                'authorization' => 'Bearer auth-value',
                'cookie' => 'laravel_session=cookie-value',
                'session_id' => 'session-value',
                'bearer_token' => 'bearer-value',
                'signature' => 'sign-value',
                'public_value' => 'visible',
            ],
        ]);

        $this->assertSame('success', $log->status);
        $this->assertSame(
            '{"processed":1,"db_password":"[FILTERED]","nested":{"access_token":"[FILTERED]","api_key":"[FILTERED]","authorization":"[FILTERED]","cookie":"[FILTERED]","session_id":"[FILTERED]","bearer_token":"[FILTERED]","signature":"[FILTERED]","public_value":"visible"}}',
            $log->output
        );
        $this->assertStringNotContainsString('plain-secret', (string) $log->output);
        $this->assertStringNotContainsString('token-value', (string) $log->output);
        $this->assertStringNotContainsString('key-value', (string) $log->output);
        $this->assertStringNotContainsString('auth-value', (string) $log->output);
        $this->assertStringNotContainsString('cookie-value', (string) $log->output);
        $this->assertStringNotContainsString('session-value', (string) $log->output);
        $this->assertStringNotContainsString('bearer-value', (string) $log->output);
        $this->assertStringNotContainsString('sign-value', (string) $log->output);
    }

    public function test_system_task_service_masks_sensitive_exception_messages(): void
    {
        $log = app(SystemTaskService::class)->run('demo:sensitive-error', function () {
            throw new \RuntimeException('连接失败 password=plain-secret token:token-value authorization=auth-value cookie:cookie-value session=session-value bearer=bearer-value access_key=key-value signature=sign-value');
        });

        $this->assertSame('failed', $log->status);
        $this->assertSame('连接失败 password=[FILTERED] token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] access_key=[FILTERED] signature=[FILTERED]', $log->error);
        $this->assertStringNotContainsString('plain-secret', (string) $log->error);
        $this->assertStringNotContainsString('token-value', (string) $log->error);
        $this->assertStringNotContainsString('auth-value', (string) $log->error);
        $this->assertStringNotContainsString('cookie-value', (string) $log->error);
        $this->assertStringNotContainsString('session-value', (string) $log->error);
        $this->assertStringNotContainsString('bearer-value', (string) $log->error);
        $this->assertStringNotContainsString('key-value', (string) $log->error);
        $this->assertStringNotContainsString('sign-value', (string) $log->error);
    }

    public function test_system_task_log_model_masks_sensitive_output_and_error_text(): void
    {
        $log = SystemTaskLog::query()->create([
            'task_name' => 'demo:model-mask',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 10,
            'output' => 'output password=plain-secret token:token-value authorization=auth-value cookie:cookie-value session=session-value bearer=bearer-value access_key=key-value signature=sign-value',
            'error' => 'error password=plain-secret token:token-value authorization=auth-value cookie:cookie-value session=session-value bearer=bearer-value access_key=key-value signature=sign-value',
        ]);

        $log->refresh();
        $this->assertSame('output password=[FILTERED] token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] access_key=[FILTERED] signature=[FILTERED]', $log->output);
        $this->assertSame('error password=[FILTERED] token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] access_key=[FILTERED] signature=[FILTERED]', $log->error);
        $this->assertStringNotContainsString('plain-secret', (string) $log->output);
        $this->assertStringNotContainsString('token-value', (string) $log->output);
        $this->assertStringNotContainsString('auth-value', (string) $log->output);
        $this->assertStringNotContainsString('cookie-value', (string) $log->output);
        $this->assertStringNotContainsString('session-value', (string) $log->output);
        $this->assertStringNotContainsString('bearer-value', (string) $log->output);
        $this->assertStringNotContainsString('key-value', (string) $log->output);
        $this->assertStringNotContainsString('sign-value', (string) $log->output);
        $this->assertStringNotContainsString('plain-secret', (string) $log->error);
        $this->assertStringNotContainsString('token-value', (string) $log->error);
        $this->assertStringNotContainsString('auth-value', (string) $log->error);
        $this->assertStringNotContainsString('cookie-value', (string) $log->error);
        $this->assertStringNotContainsString('session-value', (string) $log->error);
        $this->assertStringNotContainsString('bearer-value', (string) $log->error);
        $this->assertStringNotContainsString('key-value', (string) $log->error);
        $this->assertStringNotContainsString('sign-value', (string) $log->error);
    }

    public function test_system_task_log_model_masks_json_text_without_breaking_quotes(): void
    {
        $log = SystemTaskLog::query()->create([
            'task_name' => 'demo:model-json-mask',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 10,
            'output' => '{"provider":"failed password=plain-secret token:token-value"}',
            'error' => '{"provider":"failed password=plain-secret token:token-value"}',
        ]);

        $log->refresh();

        $this->assertSame('{"provider":"failed password=[FILTERED] token:[FILTERED]"}', $log->output);
        $this->assertSame('{"provider":"failed password=[FILTERED] token:[FILTERED]"}', $log->error);
    }

    public function test_host_usage_command_writes_system_task_log(): void
    {
        $this->artisan('host:sync-usage')->assertExitCode(0);

        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'host:sync-usage',
            'status' => 'success',
        ]);
    }

    public function test_billing_commands_write_system_task_logs(): void
    {
        $this->artisan('billing:generate-invoices')->assertExitCode(0);
        $this->artisan('billing:suspend-overdue')->assertExitCode(0);

        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'billing:generate-invoices',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'billing:suspend-overdue',
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

    public function test_admin_system_task_filter_includes_notification_recovery_task(): void
    {
        SystemTaskLog::query()->create([
            'task_name' => 'notifications:recover-stale',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 10,
            'output' => '{"email":1,"sms":1}',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.system-tasks.index', ['task_name' => 'notifications:recover-stale']))
            ->assertOk()
            ->assertSee('notifications:recover-stale')
            ->assertSee('{"email":1,"sms":1}');
    }

    public function test_admin_system_task_filter_includes_billing_tasks(): void
    {
        SystemTaskLog::query()->create([
            'task_name' => 'billing:generate-invoices',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 10,
            'output' => '{"generated":0}',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.system-tasks.index', ['task_name' => 'billing:generate-invoices']))
            ->assertOk()
            ->assertSee('billing:generate-invoices')
            ->assertSee('{"generated":0}');
    }

    public function test_admin_system_task_filters_ignore_array_query_values(): void
    {
        SystemTaskLog::query()->create([
            'task_name' => 'host:sync-usage',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 10,
            'output' => 'array filter smoke',
            'error' => '数组筛选测试',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.system-tasks.index', [
                'task_name' => ['host:sync-usage'],
                'status' => ['failed'],
            ]))
            ->assertOk()
            ->assertSee('host:sync-usage');
    }

    public function test_non_system_task_admin_cannot_view_system_task_logs(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'task-limited-' . random_int(1000, 9999),
            'email' => 'task-limited-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-tasks.index'))
            ->assertForbidden();
    }

    public function test_admin_can_manually_trigger_system_task_and_audit_action(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-tasks.run'), [
                'task_name' => 'notifications:recover-stale',
            ])
            ->assertRedirect(route('admin.system-tasks.index', ['task_name' => 'notifications:recover-stale']));

        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'notifications:recover-stale',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'system_task.run_manual',
            'result' => 'success',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admin-action-logs.index', ['action' => 'system_task.run_manual']))
            ->assertOk()
            ->assertSee('system_task.run_manual');
    }

    public function test_scheduler_contains_host_tasks(): void
    {
        $this->artisan('schedule:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('billing:generate-invoices')
            ->expectsOutputToContain('billing:suspend-overdue')
            ->expectsOutputToContain('host:sync-usage')
            ->expectsOutputToContain('host:send-due-reminders')
            ->expectsOutputToContain('notifications:recover-stale')
            ->expectsOutputToContain('marketing-automations:process-due')
            ->expectsOutputToContain('api-quotas:check-alerts');
    }

    public function test_notification_recovery_command_marks_stale_processing_logs_failed(): void
    {
        $email = EmailLog::query()->create([
            'to' => 'stale-command@example.com',
            'subject' => 'Stale',
            'body' => 'body',
            'provider' => 'smtp',
            'status' => 'processing',
            'success' => false,
            'payload' => [],
            'attempts' => 1,
        ]);
        $sms = SmsLog::query()->create([
            'phone' => '13800137777',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => 'stale',
            'provider' => 'aliyun',
            'status' => 'processing',
            'success' => false,
            'payload' => [],
            'attempts' => 1,
        ]);
        EmailLog::query()->whereKey($email->id)->update(['updated_at' => now()->subMinutes(30)]);
        SmsLog::query()->whereKey($sms->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->artisan('notifications:recover-stale', ['--minutes' => 15])
            ->assertExitCode(0);

        $this->assertSame('failed', $email->fresh()->status);
        $this->assertSame('Email processing timeout', $email->fresh()->error);
        $this->assertSame(1, $email->fresh()->attempts);
        $this->assertSame('failed', $sms->fresh()->status);
        $this->assertSame('SMS processing timeout', $sms->fresh()->error);
        $this->assertSame(1, $sms->fresh()->attempts);
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'notifications:recover-stale',
            'status' => 'success',
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'admin-task-' . random_int(1000, 9999),
            'email' => 'admin-task-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
