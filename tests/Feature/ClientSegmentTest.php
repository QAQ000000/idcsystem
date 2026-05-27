<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientSegment;
use App\Modules\User\Services\ClientSegmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientSegmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_segment_can_add_and_remove_clients(): void
    {
        $client = $this->client('static-client');
        $segment = ClientSegment::query()->create([
            'name' => '静态 VIP',
            'type' => 'static',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.client-segments.members.store', $segment), [
                'client_ids' => (string) $client->id,
            ])
            ->assertRedirect(route('admin.client-segments.show', $segment));

        $this->assertDatabaseHas('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $client->id,
        ]);
        $this->assertSame(1, $segment->fresh()->clients_count);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.client-segments.members.destroy', [$segment, $client]))
            ->assertRedirect(route('admin.client-segments.show', $segment));

        $this->assertDatabaseMissing('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $client->id,
        ]);
        $this->assertSame(0, $segment->fresh()->clients_count);
    }

    public function test_dynamic_segment_calculates_from_rules(): void
    {
        $matching = $this->client('matching-client', ['credit' => 200]);
        $other = $this->client('other-client', ['credit' => 20]);
        Invoice::query()->create([
            'client_id' => $matching->id,
            'invoice_number' => 'INV-SEGMENT-001',
            'subtotal' => 300,
            'tax' => 0,
            'total' => 300,
            'status' => 'Paid',
        ]);
        Invoice::query()->create([
            'client_id' => $other->id,
            'invoice_number' => 'INV-SEGMENT-002',
            'subtotal' => 50,
            'tax' => 0,
            'total' => 50,
            'status' => 'Paid',
        ]);
        $this->assertSame(1, Client::query()->where('credit', '>=', 100)->count());
        $this->assertSame([$matching->id], Invoice::query()
            ->select('client_id')
            ->where('status', 'Paid')
            ->groupBy('client_id')
            ->havingRaw('SUM(total) >= ?', [200])
            ->pluck('client_id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        $segment = ClientSegment::query()->create([
            'name' => '高价值客户',
            'type' => 'dynamic',
            'rules' => [
                ['field' => 'credit_balance', 'operator' => '>=', 'value' => 100],
                ['field' => 'total_spent', 'operator' => '>=', 'value' => 200],
            ],
        ]);
        $this->assertTrue($segment->isDynamic());

        $count = app(ClientSegmentService::class)->calculate($segment);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $matching->id,
        ]);
        $this->assertDatabaseMissing('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $other->id,
        ]);
        $this->assertNotNull($segment->fresh()->last_calculated_at);
    }

    public function test_dynamic_segment_refresh_replaces_stale_members(): void
    {
        $client = $this->client('stale-client', ['credit' => 150]);
        $segment = ClientSegment::query()->create([
            'name' => '余额客户',
            'type' => 'dynamic',
            'rules' => [['field' => 'credit_balance', 'operator' => '>=', 'value' => 100]],
        ]);

        app(ClientSegmentService::class)->calculate($segment);
        $this->assertSame(1, $segment->fresh()->clients_count);

        $client->update(['credit' => 10]);
        app(ClientSegmentService::class)->calculate($segment->fresh());

        $this->assertSame(0, $segment->fresh()->clients_count);
        $this->assertDatabaseMissing('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_dynamic_segment_supports_active_host_count_and_refresh_command(): void
    {
        $client = $this->client('host-client');
        $this->host($client, 'Active');
        $this->host($client, 'Suspended');
        $segment = ClientSegment::query()->create([
            'name' => '有活跃服务',
            'type' => 'dynamic',
            'rules' => [['field' => 'active_hosts_count', 'operator' => '>=', 'value' => 1]],
        ]);

        $this->artisan('client-segments:refresh')->assertExitCode(0);

        $this->assertSame(1, $segment->fresh()->clients_count);
        $this->assertDatabaseHas('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_admin_can_create_dynamic_segment_from_json_rules(): void
    {
        $client = $this->client('json-client', ['credit' => 500]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.client-segments.store'), [
                'name' => 'JSON 规则分群',
                'type' => 'dynamic',
                'rules' => json_encode([
                    ['field' => 'credit_balance', 'operator' => '>=', 'value' => 300],
                ]),
            ])
            ->assertRedirect();

        $segment = ClientSegment::query()->where('name', 'JSON 规则分群')->firstOrFail();
        $this->assertSame(1, $segment->clients_count);
        $this->assertDatabaseHas('client_segment_members', [
            'segment_id' => $segment->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_invalid_dynamic_segment_rules_are_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.client-segments.create'))
            ->post(route('admin.client-segments.store'), [
                'name' => '错误规则',
                'type' => 'dynamic',
                'rules' => '[{"field":"unknown","operator":">=","value":1}]',
            ])
            ->assertRedirect(route('admin.client-segments.create'))
            ->assertSessionHasErrors('rules');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'segment-admin-' . random_int(1000, 9999),
            'email' => 'segment-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(string $username, array $overrides = []): Client
    {
        return Client::query()->create(array_merge([
            'username' => $username . '-' . random_int(1000, 9999),
            'email' => $username . '-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ], $overrides));
    }

    private function host(Client $client, string $status): Host
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '分群产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Segment VPS ' . random_int(1000, 9999),
            'type' => 'hosting',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-SEGMENT-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
        ]);

        return Host::query()->create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain' => 'segment-' . random_int(1000, 9999) . '.example.com',
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'status' => $status,
        ]);
    }
}
