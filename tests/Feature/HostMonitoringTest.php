<?php

namespace Tests\Feature;

use App\Models\HostUsageSnapshot;
use App\Models\Plugin;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        $this->assertDatabaseHas('host_usage_snapshots', [
            'host_id' => $plainHost->id,
            'error' => 'MockServer 模拟用量采集失败',
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $plainHost->id,
            'action' => 'usage_sync_failed',
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
    }

    public function test_usage_sync_command_writes_snapshots(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Active']);

        $this->artisan('host:sync-usage')->assertExitCode(0);

        $this->assertDatabaseHas('host_usage_snapshots', ['host_id' => $host->id]);
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
        return AdminUser::query()->create([
            'username' => 'admin-monitor-' . random_int(1000, 9999),
            'email' => 'admin-monitor-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
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
