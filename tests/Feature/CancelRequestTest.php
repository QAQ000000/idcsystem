<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Order\Models\CancelRequest;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CancelRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_submit_cancel_request_once(): void
    {
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.cancel', $host), [
                'type' => 'end_of_billing_period',
                'reason' => '不再使用',
            ])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHas('status', '取消申请已提交，请等待管理员审核');

        $this->assertDatabaseHas('cancel_requests', [
            'host_id' => $host->id,
            'client_id' => $host->client_id,
            'type' => 'end_of_billing_period',
            'status' => 'pending',
        ]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.cancel', $host), [
                'type' => 'immediate',
            ])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('cancel_request');

        $this->assertSame(1, CancelRequest::query()->where('host_id', $host->id)->count());
    }

    public function test_client_cannot_submit_cancel_request_for_other_or_invalid_host(): void
    {
        $host = $this->host(['status' => 'Terminated']);
        $other = $this->client();

        $this->actingAs($other, 'client')
            ->post(route('client.hosts.cancel', $host), ['type' => 'immediate'])
            ->assertForbidden();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.cancel', $host), ['type' => 'immediate'])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('cancel_request');

        $this->assertDatabaseCount('cancel_requests', 0);
    }

    public function test_admin_can_approve_immediate_cancel_request_and_terminate_host(): void
    {
        $host = $this->host();
        $cancelRequest = CancelRequest::query()->create([
            'client_id' => $host->client_id,
            'host_id' => $host->id,
            'type' => 'immediate',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.cancel-requests.approve', $cancelRequest), [
                'admin_notes' => '同意取消',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '取消申请已批准');

        $this->assertSame('completed', $cancelRequest->fresh()->status);
        $this->assertSame('Terminated', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'cancel_request_completed',
        ]);
    }

    public function test_admin_can_reject_cancel_request_without_terminating_host(): void
    {
        $host = $this->host();
        $cancelRequest = CancelRequest::query()->create([
            'client_id' => $host->client_id,
            'host_id' => $host->id,
            'type' => 'end_of_billing_period',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.cancel-requests.reject', $cancelRequest), [
                'admin_notes' => '仍有未完成沟通',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '取消申请已拒绝');

        $this->assertSame('rejected', $cancelRequest->fresh()->status);
        $this->assertSame('Active', $host->fresh()->status);
    }

    public function test_process_approved_end_of_period_cancel_requests(): void
    {
        $host = $this->host(['next_due_date' => now()->subDay()]);
        $cancelRequest = CancelRequest::query()->create([
            'client_id' => $host->client_id,
            'host_id' => $host->id,
            'type' => 'end_of_billing_period',
            'status' => 'approved',
            'approved_at' => now()->subDay(),
        ]);

        $this->artisan('cancel:process-approved')
            ->assertExitCode(0);

        $this->assertSame('completed', $cancelRequest->fresh()->status);
        $this->assertSame('Terminated', $host->fresh()->status);
    }

    public function test_admin_cancel_request_index_lists_pending_requests(): void
    {
        $host = $this->host();
        CancelRequest::query()->create([
            'client_id' => $host->client_id,
            'host_id' => $host->id,
            'type' => 'end_of_billing_period',
            'reason' => '预算调整',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.cancel-requests.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('预算调整')
            ->assertSee($host->product->name);
    }

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $product = $this->product();
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-CANCEL-' . random_int(1000, 9999),
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

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'cancel-client-' . random_int(1000, 9999),
            'email' => 'cancel-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '取消申请产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Cancel VPS ' . random_int(1000, 9999),
            'description' => '取消申请测试产品',
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => 50,
        ]);

        return $product;
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'cancel-admin-' . random_int(1000, 9999),
            'email' => 'cancel-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
