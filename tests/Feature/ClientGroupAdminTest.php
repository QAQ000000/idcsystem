<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientGroupAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_unused_client_group(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.client-groups.store'), [
                'name' => 'VIP',
                'discount_percent' => 12.5,
                'color' => '#16a34a',
            ])
            ->assertRedirect(route('admin.client-groups.index'))
            ->assertSessionHas('status', '客户分组已创建');

        $group = ClientGroup::query()->where('name', 'VIP')->firstOrFail();
        $this->assertSame('12.50', (string) $group->discount_percent);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.client-groups.update', $group), [
                'name' => 'VIP Plus',
                'discount_percent' => 15,
                'color' => '#0f766e',
            ])
            ->assertRedirect(route('admin.client-groups.index'))
            ->assertSessionHas('status', '客户分组已保存');

        $this->assertDatabaseHas('client_groups', [
            'id' => $group->id,
            'name' => 'VIP Plus',
            'discount_percent' => 15,
        ]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.client-groups.destroy', $group->fresh()))
            ->assertRedirect(route('admin.client-groups.index'))
            ->assertSessionHas('status', '客户分组已删除');

        $this->assertDatabaseMissing('client_groups', ['id' => $group->id]);
    }

    public function test_admin_cannot_delete_group_with_clients(): void
    {
        $group = ClientGroup::query()->create([
            'name' => 'Bound Group',
            'discount_percent' => 5,
        ]);
        Client::query()->create([
            'username' => 'group-client',
            'email' => 'group-client@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
            'group_id' => $group->id,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.client-groups.destroy', $group))
            ->assertRedirect(route('admin.client-groups.index'))
            ->assertSessionHas('error', '该分组下仍有客户，不能删除');

        $this->assertDatabaseHas('client_groups', ['id' => $group->id]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'client-group-admin',
            'email' => 'client-group-admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
