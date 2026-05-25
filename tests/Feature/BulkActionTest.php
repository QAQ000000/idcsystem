<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Plugin;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BulkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_bulk_suspend_activate_credit_and_email_clients(): void
    {
        Mail::fake();
        $this->installSmtp();
        app(SettingsService::class)->set('mail_queue_enabled', false, 'mail');

        $admin = $this->admin();
        $clientA = $this->client('bulk-a', 'bulk-a@example.com');
        $clientB = $this->client('bulk-b', 'bulk-b@example.com');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.bulk-action'), [
                'action' => 'suspend',
                'client_ids' => [$clientA->id, $clientB->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '批量操作完成，成功处理 2 个客户');

        $this->assertSame(2, $clientA->fresh()->status);
        $this->assertSame(2, $clientB->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.bulk-action'), [
                'action' => 'activate',
                'client_ids' => [$clientA->id, $clientB->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '批量操作完成，成功处理 2 个客户');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.bulk-action'), [
                'action' => 'add_credit',
                'client_ids' => [$clientA->id, $clientB->id],
                'amount' => 25,
                'description' => '批量测试充值',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '批量操作完成，成功处理 2 个客户');

        $this->assertSame('25.00', (string) $clientA->fresh()->credit);
        $this->assertSame('25.00', (string) $clientB->fresh()->credit);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.bulk-action'), [
                'action' => 'send_email',
                'client_ids' => [$clientA->id, $clientB->id],
                'subject' => '批量通知',
                'body' => '这是批量邮件内容',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '批量操作完成，成功处理 2 个客户');

        $this->assertSame(2, EmailLog::query()->where('template', 'custom_email')->count());
        $this->assertDatabaseHas('admin_action_logs', ['action' => 'client.bulk_send_email', 'result' => 'success']);
    }

    public function test_admin_can_bulk_suspend_unsuspend_and_terminate_hosts(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $hostA = $this->host($client, 'bulk-a.example.com');
        $hostB = $this->host($client, 'bulk-b.example.com');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.bulk-action'), [
                'action' => 'suspend',
                'host_ids' => [$hostA->id, $hostB->id],
                'reason' => '批量维护',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '批量操作完成，成功处理 2 个服务');

        $this->assertSame('Suspended', $hostA->fresh()->status);
        $this->assertSame('Suspended', $hostB->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.bulk-action'), [
                'action' => 'unsuspend',
                'host_ids' => [$hostA->id, $hostB->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '批量操作完成，成功处理 2 个服务');

        $this->assertSame('Active', $hostA->fresh()->status);
        $this->assertSame('Active', $hostB->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.hosts.bulk-action'), [
                'action' => 'terminate',
                'host_ids' => [$hostA->id, $hostB->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '批量操作完成，成功处理 2 个服务');

        $this->assertSame('Terminated', $hostA->fresh()->status);
        $this->assertSame('Terminated', $hostB->fresh()->status);
        $this->assertDatabaseHas('admin_action_logs', ['action' => 'host.bulk_terminate', 'result' => 'success']);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'bulk-admin',
            'email' => 'bulk-admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(string $username = 'bulk-client', string $email = 'bulk-client@example.com'): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function host(Client $client, string $domain): Host
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '批量产品组']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => '批量 VPS ' . $domain,
            'type' => 'vps',
            'stock_control' => false,
        ]);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-BULK-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 100,
            'currency_id' => $client->currency_id,
        ]);

        return Host::query()->create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain' => $domain,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 100,
            'recurring_amount' => 100,
            'status' => 'Active',
        ]);
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        Plugin::query()->where('name', 'smtp')->update([
            'config' => ['host' => 'smtp.example.com', 'port' => 587],
        ]);
    }
}
