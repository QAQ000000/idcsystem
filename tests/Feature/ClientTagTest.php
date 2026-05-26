<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Invoice;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientTag;
use App\Modules\User\Models\TagAutoRule;
use App\Modules\User\Services\ClientTagService;
use Database\Seeders\ClientTagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_tag_seeder_creates_system_tags(): void
    {
        $this->seed(ClientTagSeeder::class);

        $this->assertDatabaseHas('client_tags', ['slug' => 'vip', 'system' => true]);
        $this->assertDatabaseHas('client_tags', ['slug' => 'high-value', 'system' => true]);
        $this->assertDatabaseHas('client_tags', ['slug' => 'risk', 'system' => true]);
    }

    public function test_auto_rule_attaches_matching_tag(): void
    {
        $client = $this->client(['credit' => 150]);
        $tag = ClientTag::query()->create([
            'name' => '高余额',
            'slug' => 'high-credit',
            'color' => '#10B981',
        ]);
        TagAutoRule::query()->create([
            'client_tag_id' => $tag->id,
            'condition_type' => 'credit_balance',
            'operator' => '>=',
            'threshold' => 100,
            'active' => true,
        ]);

        app(ClientTagService::class)->applyAutoRules($client);

        $this->assertDatabaseHas('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }

    public function test_total_spent_rule_uses_paid_invoice_total(): void
    {
        $client = $this->client();
        $tag = ClientTag::query()->create([
            'name' => '高价值',
            'slug' => 'high-value',
            'color' => '#10B981',
        ]);
        TagAutoRule::query()->create([
            'client_tag_id' => $tag->id,
            'condition_type' => 'total_spent',
            'operator' => '>=',
            'threshold' => 300,
            'active' => true,
        ]);
        Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-TAG-001',
            'subtotal' => 300,
            'tax' => 0,
            'total' => 300,
            'status' => 'Paid',
        ]);

        app(ClientTagService::class)->applyAutoRules($client);

        $this->assertTrue($client->fresh('tags')->tags->contains($tag));
    }

    public function test_admin_can_create_and_attach_client_tag(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.client-tags.store'), [
                'name' => 'VIP',
                'slug' => 'vip',
                'color' => '#F59E0B',
            ])
            ->assertRedirect(route('admin.client-tags.index'));

        $tag = ClientTag::query()->where('slug', 'vip')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.tags.attach', $client), [
                'client_tag_id' => $tag->id,
            ])
            ->assertRedirect(route('admin.clients.show', $client));

        $this->assertDatabaseHas('client_tag_pivot', [
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'client-tag-admin-' . random_int(1000, 9999),
            'email' => 'client-tag-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(array $overrides = []): Client
    {
        return Client::query()->create(array_merge([
            'username' => 'client-tag-user-' . random_int(1000, 9999),
            'email' => 'client-tag-user-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ], $overrides));
    }
}
