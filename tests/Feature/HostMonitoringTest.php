<?php

namespace Tests\Feature;

use App\Models\HostUsageSnapshot;
use App\Models\DueReminder;
use App\Models\Plugin;
use App\Models\UsageAlert;
use App\Models\UsageAlertLog;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\HostMonitoringService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HostMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_sync_writes_success_snapshot(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Active']);

        $result = app(HostMonitoringService::class)->syncUsage();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['success']);
        $this->assertDatabaseHas('host_usage_snapshots', [
            'host_id' => $host->id,
            'error' => null,
        ]);
    }

    public function test_usage_sync_does_not_pass_host_password_to_server_module(): void
    {
        $this->installMockServer(['fail_when_usage_receives_password' => true]);
        $host = $this->host(['status' => 'Active']);

        app(HostMonitoringService::class)->syncUsage();

        $this->assertDatabaseHas('host_usage_snapshots', [
            'host_id' => $host->id,
            'error' => null,
        ]);
    }

    public function test_usage_sync_failure_writes_snapshot_and_continues(): void
    {
        $this->installMockServer(['fail_usage' => true]);
        $failedHost = $this->host(['status' => 'Active']);
        Product::query()->whereKey($failedHost->product_id)->update(['server_type' => null]);
        $plainHost = $this->host(['status' => 'Suspended']);

        $result = app(HostMonitoringService::class)->syncUsage();

        $this->assertSame(2, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(2, $result['failed']);
        $this->assertDatabaseHas('host_usage_snapshots', [
            'host_id' => $failedHost->id,
            'error' => 'Server module unavailable',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $failedHost->id,
            'action' => 'usage_sync_failed',
            'message' => 'Server module unavailable',
        ]);
        $this->assertDatabaseHas('host_usage_snapshots', [
            'host_id' => $plainHost->id,
            'error' => 'MockServer 模拟用量采集失败',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $plainHost->id,
            'action' => 'usage_sync_failed',
        ]);
    }

    public function test_usage_sync_rejects_invalid_usage_metric_values(): void
    {
        $this->installMockServer(['invalid_usage_metric' => true]);
        $host = $this->host(['status' => 'Active']);

        $result = app(HostMonitoringService::class)->syncUsage();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('host_usage_snapshots', [
            'host_id' => $host->id,
            'cpu' => null,
            'error' => 'Invalid usage metric: cpu',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'usage_sync_failed',
            'message' => 'Invalid usage metric: cpu',
        ]);
        $this->assertDatabaseMissing('host_usage_snapshots', [
            'host_id' => $host->id,
            'cpu' => 0,
            'error' => null,
        ]);
    }

    public function test_admin_host_detail_shows_recent_usage_snapshots(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Active']);
        HostUsageSnapshot::query()->create([
            'host_id' => $host->id,
            'cpu' => 42,
            'memory' => 1024,
            'disk' => 40,
            'bandwidth' => 200,
            'collected_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('最近用量记录')
            ->assertSee('42.00%')
            ->assertSee('1024.00 MB');
    }

    public function test_host_usage_snapshot_masks_sensitive_error_text(): void
    {
        $host = $this->host(['status' => 'Active']);

        $snapshot = HostUsageSnapshot::query()->create([
            'host_id' => $host->id,
            'collected_at' => now(),
            'error' => 'usage failed password=plain-secret token:token-value signature=sign-value',
        ]);

        $snapshot->refresh();
        $this->assertStringContainsString('password=[FILTERED]', $snapshot->error);
        $this->assertStringContainsString('token:[FILTERED]', $snapshot->error);
        $this->assertStringContainsString('signature=[FILTERED]', $snapshot->error);
        $this->assertStringNotContainsString('plain-secret', $snapshot->error);
        $this->assertStringNotContainsString('token-value', $snapshot->error);
        $this->assertStringNotContainsString('sign-value', $snapshot->error);
    }

    public function test_admin_host_detail_reports_unavailable_bound_server_module(): void
    {
        $host = $this->host(['status' => 'Active']);
        Product::query()->whereKey($host->product_id)->update(['server_type' => 'missing_server']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host->fresh(['client', 'product'])))
            ->assertOk()
            ->assertSee('实时用量读取失败')
            ->assertSee('服务器模块不可用：missing_server');
    }

    public function test_due_reminder_command_triggers_notifications(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $host = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);

        $this->artisan('host:send-due-reminders', ['--days' => 7])->assertExitCode(0);

        $this->assertDatabaseHas('email_logs', [
            'to' => $host->client->email,
            'template' => 'host_due_reminder',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'due_reminder',
        ]);
        $this->assertDatabaseHas('due_reminders', [
            'host_id' => $host->id,
            'days_before' => 3,
        ]);
    }

    public function test_due_reminders_use_configured_multiple_days_and_do_not_repeat_successful_reminders(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        config(['billing.reminder_days' => [7, 3, 1]]);
        $sevenDayHost = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(7),
        ]);
        $threeDayHost = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);
        $oneDayHost = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDay(),
        ]);
        $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(5),
        ]);

        $first = app(HostMonitoringService::class)->sendDueReminders();
        $second = app(HostMonitoringService::class)->sendDueReminders();

        $this->assertSame(3, $first['processed']);
        $this->assertSame(3, $first['notified']);
        $this->assertSame(3, $second['processed']);
        $this->assertSame(0, $second['notified']);
        $this->assertDatabaseHas('due_reminders', ['host_id' => $sevenDayHost->id, 'days_before' => 7]);
        $this->assertDatabaseHas('due_reminders', ['host_id' => $threeDayHost->id, 'days_before' => 3]);
        $this->assertDatabaseHas('due_reminders', ['host_id' => $oneDayHost->id, 'days_before' => 1]);
        $this->assertSame(3, DueReminder::query()->count());
    }

    public function test_due_reminders_skip_inactive_and_deleted_clients(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $inactiveHost = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);
        $inactiveHost->client->update(['status' => 2]);
        $deletedHost = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);
        $deletedHost->client->delete();

        $result = app(HostMonitoringService::class)->sendDueReminders(7);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(0, $result['notified']);
        $this->assertDatabaseCount('email_logs', 0);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $inactiveHost->id,
            'action' => 'due_reminder_failed',
            'message' => '服务到期提醒发送失败',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $deletedHost->id,
            'action' => 'due_reminder_failed',
            'message' => '服务到期提醒发送失败',
        ]);
    }

    public function test_due_reminders_retry_failed_attempts_until_success_record_exists(): void
    {
        $host = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);
        $this->instance(NotificationService::class, new class extends NotificationService {
            public function notifyClient(\App\Modules\User\Models\Client $client, string $event, array $variables = []): array
            {
                return ['mail' => false, 'sms' => null, 'errors' => ['mail' => 'disabled']];
            }
        });
        $first = app(HostMonitoringService::class)->sendDueReminders(7);
        $second = app(HostMonitoringService::class)->sendDueReminders(7);

        $this->assertSame(1, $first['processed']);
        $this->assertSame(1, $second['processed']);
        $this->assertSame(2, \App\Models\HostActionLog::query()
            ->where('host_id', $host->id)
            ->where('action', 'due_reminder_failed')
            ->count());
        $this->assertDatabaseMissing('due_reminders', ['host_id' => $host->id, 'days_before' => 7]);
    }

    public function test_due_reminders_continue_after_single_notification_exception(): void
    {
        $first = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);
        $second = $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);

        $this->instance(NotificationService::class, new class extends NotificationService {
            private int $calls = 0;

            public function notifyClient(\App\Modules\User\Models\Client $client, string $event, array $variables = []): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('notify failed password=plain-secret token:token-value');
                }

                return ['mail' => true, 'sms' => null, 'errors' => []];
            }
        });

        $result = app(HostMonitoringService::class)->sendDueReminders(7);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['notified']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $first->id,
            'action' => 'due_reminder_failed',
            'message' => '服务到期提醒发送失败',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $second->id,
            'action' => 'due_reminder',
            'message' => '服务到期提醒已触发',
        ]);
        $log = \App\Models\HostActionLog::query()
            ->where('host_id', $first->id)
            ->where('action', 'due_reminder_failed')
            ->firstOrFail();
        $this->assertSame('notify failed password=[FILTERED] token:[FILTERED]', $log->meta['error']);
    }

    public function test_due_reminder_command_marks_partial_notification_failures_failed(): void
    {
        $this->host([
            'status' => 'Active',
            'next_due_date' => now()->addDays(3),
        ]);
        $this->instance(NotificationService::class, new class extends NotificationService {
            public function notifyClient(\App\Modules\User\Models\Client $client, string $event, array $variables = []): array
            {
                throw new \RuntimeException('notify failed');
            }
        });

        $this->artisan('host:send-due-reminders', ['--days' => 7])->assertExitCode(1);

        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'host:send-due-reminders',
            'status' => 'failed',
            'error' => '1 个子任务失败',
        ]);
    }

    public function test_usage_sync_command_writes_snapshots(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Active']);

        $this->artisan('host:sync-usage')->assertExitCode(0);

        $this->assertDatabaseHas('host_usage_snapshots', ['host_id' => $host->id]);
    }

    public function test_client_can_manage_usage_alerts(): void
    {
        $host = $this->host(['status' => 'Active']);
        HostUsageSnapshot::query()->create([
            'host_id' => $host->id,
            'cpu' => 20,
            'memory' => 30,
            'disk' => 40,
            'bandwidth' => 50,
            'collected_at' => now(),
        ]);

        $this->actingAs($host->client, 'client')
            ->get(route('client.hosts.show', $host))
            ->assertOk()
            ->assertSee('用量告警');

        $this->actingAs($host->client, 'client')
            ->get(route('client.hosts.alerts.index', $host))
            ->assertOk()
            ->assertSee('新增或更新规则')
            ->assertSee('40.00%');

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.alerts.store', $host), [
                'metric' => 'disk',
                'threshold' => 80,
                'active' => 1,
            ])
            ->assertRedirect(route('client.hosts.alerts.index', $host))
            ->assertSessionHas('status', '用量告警已保存');

        $alert = UsageAlert::query()->where('host_id', $host->id)->where('metric', 'disk')->firstOrFail();
        $this->assertSame(80, $alert->threshold);

        $this->actingAs($host->client, 'client')
            ->delete(route('client.hosts.alerts.destroy', [$host, $alert]))
            ->assertRedirect(route('client.hosts.alerts.index', $host))
            ->assertSessionHas('status', '用量告警已删除');

        $this->assertDatabaseMissing('usage_alerts', ['id' => $alert->id]);
    }

    public function test_usage_alert_check_triggers_notification_and_respects_cooldown(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $host = $this->host(['status' => 'Active']);
        HostUsageSnapshot::query()->create([
            'host_id' => $host->id,
            'cpu' => 85,
            'memory' => 30,
            'disk' => 75,
            'bandwidth' => 20,
            'collected_at' => now(),
        ]);
        $alert = UsageAlert::query()->create([
            'host_id' => $host->id,
            'metric' => 'cpu',
            'threshold' => 80,
            'active' => true,
        ]);

        $first = app(\App\Services\UsageAlertService::class)->check($host);
        $second = app(\App\Services\UsageAlertService::class)->check($host);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertDatabaseHas('usage_alert_logs', [
            'alert_id' => $alert->id,
            'host_id' => $host->id,
            'metric' => 'cpu',
            'threshold' => 80,
        ]);
        $this->assertDatabaseHas('email_logs', [
            'to' => $host->client->email,
            'template' => 'usage_alert',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'usage_alert',
            'message' => '服务用量告警已触发',
        ]);
        $this->assertSame(1, UsageAlertLog::query()->where('alert_id', $alert->id)->count());

        $alert->refresh()->update(['last_triggered_at' => now()->subMinutes(61)]);
        $this->assertSame(1, app(\App\Services\UsageAlertService::class)->check($host));
        $this->assertSame(2, UsageAlertLog::query()->where('alert_id', $alert->id)->count());
    }

    public function test_usage_alert_command_writes_system_task_log(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $host = $this->host(['status' => 'Active']);
        HostUsageSnapshot::query()->create([
            'host_id' => $host->id,
            'cpu' => 10,
            'memory' => 30,
            'disk' => 91,
            'bandwidth' => 20,
            'collected_at' => now(),
        ]);
        UsageAlert::query()->create([
            'host_id' => $host->id,
            'metric' => 'disk',
            'threshold' => 90,
            'active' => true,
        ]);

        $this->artisan('usage:check-alerts')->assertExitCode(0);

        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'usage:check-alerts',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('usage_alert_logs', [
            'host_id' => $host->id,
            'metric' => 'disk',
            'threshold' => 90,
        ]);
    }

    private function installMockServer(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        Plugin::query()->where('name', 'mock_server')->update(['config' => $config]);
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        Plugin::query()->where('name', 'smtp')->update(['config' => []]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'admin-monitor-' . random_int(1000, 9999),
            'email' => 'admin-monitor-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'host-viewer', 'guard_name' => 'web']);
        $admin->syncRoles(['host-viewer']);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'host.view', 'guard_name' => 'web']));

        return $admin;
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'monitor-client-' . random_int(1000, 9999),
            'email' => 'monitor-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '监控产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => $name,
            'description' => $name . ' 产品说明',
            'type' => 'vps',
            'auto_setup' => 'payment',
            'server_type' => 'mock_server',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id') ?: 1,
            'monthly' => 50,
        ]);

        return $product;
    }

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $product = $this->product('Monitor VPS ' . random_int(1000, 9999));
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-MONITOR-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $client->currency_id,
        ]);

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'next_due_date' => now()->addMonth(),
            'next_invoice_date' => now()->addDays(23),
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides))->load(['client', 'product']);
    }
}
