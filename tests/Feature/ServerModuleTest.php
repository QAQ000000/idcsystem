<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ServerModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_server_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('server'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'mock_server'));
        $this->assertTrue($manager->install('server', 'mock_server'));
        $this->assertDatabaseHas('plugins', ['name' => 'mock_server', 'type' => 'server', 'status' => 0]);

        $this->assertTrue($manager->enable('mock_server'));
        $this->assertDatabaseHas('plugins', ['name' => 'mock_server', 'status' => 1]);
    }

    public function test_admin_can_bind_product_to_server_type(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $group = ProductGroup::query()->create(['name' => '服务器产品']);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.products.store'), [
            'group_id' => $group->id,
            'name' => 'Mock VPS',
            'type' => 'vps',
            'auto_setup' => 'payment',
            'server_type' => 'mock_server',
            'stock_qty' => 10,
        ]);

        $product = Product::query()->where('name', 'Mock VPS')->firstOrFail();
        $response->assertRedirect(route('admin.products.show', $product));
        $this->assertSame('mock_server', $product->server_type);
    }

    public function test_paid_order_provisions_host_through_mock_server(): void
    {
        Mail::fake();
        $this->installMockServer();
        $client = $this->client();
        $product = $this->product('Mock VPS', 50);

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);

        $this->assertTrue(app(OrderService::class)->markAsPaid($order->fresh(['invoice', 'hosts.product']), 'manual_pay', 'SERVER-001'));

        $host = $order->hosts()->firstOrFail()->fresh();
        $this->assertSame('Active', $host->status);
        $this->assertSame('mock' . $host->id, $host->username);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_admin_can_suspend_unsuspend_and_terminate_host(): void
    {
        $this->installMockServer();
        $admin = $this->admin();
        $host = $this->host();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'suspend', 'reason' => '测试暂停'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Suspended', $host->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'unsuspend'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Active', $host->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.action', $host), ['action' => 'terminate'])
            ->assertRedirect(route('admin.hosts.show', $host));
        $this->assertSame('Terminated', $host->fresh()->status);

        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'suspend']);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'unsuspend']);
        $this->assertDatabaseHas('host_action_logs', ['host_id' => $host->id, 'action' => 'terminate']);
    }

    public function test_admin_host_detail_shows_usage_stats_and_failure_reason(): void
    {
        $this->installMockServer();
        $host = $this->host(['status' => 'Pending']);
        \App\Models\HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => 'provision_failed',
            'message' => 'MockServer 模拟开通失败',
            'meta' => ['result' => ['success' => false]],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('用量统计')
            ->assertSee('开通失败原因')
            ->assertSee('MockServer 模拟开通失败');
    }

    public function test_admin_host_list_can_filter_by_client_product_and_status(): void
    {
        $admin = $this->admin();
        $host = $this->host(['status' => 'Suspended']);
        $other = $this->host(['status' => 'Active']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.index', [
                'client_id' => $host->client_id,
                'product_id' => $host->product_id,
                'status' => 'Suspended',
            ]))
            ->assertOk()
            ->assertSee('#' . $host->id)
            ->assertDontSee('#' . $other->id);
    }

    private function installMockServer(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        Plugin::query()->where('name', 'mock_server')->update(['config' => []]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->create([
            'username' => 'admin-server-' . random_int(1000, 9999),
            'email' => 'admin-server-' . random_int(1000, 9999) . '@example.com',
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
            'username' => 'server-client-' . random_int(1000, 9999),
            'email' => 'server-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name, float $monthly): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => 'MockServer 产品']);
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
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => $monthly,
        ]);

        return $product;
    }

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $product = $this->product('Admin Mock VPS ' . random_int(1000, 9999), 50);
        $order = \App\Modules\Order\Models\Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-SERVER-' . random_int(1000, 9999),
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
