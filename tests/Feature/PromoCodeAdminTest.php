<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Order\Models\PromoCode;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PromoCodeAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_toggle_and_delete_unused_promo_code(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.promo-codes.store'), [
                'code' => 'save20',
                'type' => 'percentage',
                'value' => 20,
                'applies_to' => 'products',
                'product_ids' => [$product->id],
                'max_uses' => 100,
                'once_per_client' => '1',
                'active' => '1',
                'starts_at' => now()->format('Y-m-d H:i:s'),
                'expires_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.promo-codes.index'))
            ->assertSessionHas('status', '优惠码已创建');

        $promo = PromoCode::query()->where('code', 'SAVE20')->firstOrFail();
        $this->assertSame('percentage', $promo->type);
        $this->assertSame([$product->id], $promo->product_ids);
        $this->assertTrue($promo->once_per_client);
        $this->assertTrue($promo->active);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.promo-codes.update', $promo), [
                'code' => 'SAVE30',
                'type' => 'fixed',
                'value' => 30,
                'applies_to' => 'all',
                'max_uses' => 0,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.promo-codes.index'))
            ->assertSessionHas('status', '优惠码已保存');

        $promo->refresh();
        $this->assertSame('SAVE30', $promo->code);
        $this->assertSame('fixed', $promo->type);
        $this->assertNull($promo->product_ids);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.promo-codes.toggle', $promo))
            ->assertRedirect(route('admin.promo-codes.index'))
            ->assertSessionHas('status', '优惠码已停用');

        $this->assertFalse($promo->fresh()->active);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.promo-codes.destroy', $promo))
            ->assertRedirect(route('admin.promo-codes.index'))
            ->assertSessionHas('status', '优惠码已删除');

        $this->assertDatabaseMissing('promo_codes', ['id' => $promo->id]);
    }

    public function test_percentage_promo_value_cannot_exceed_one_hundred(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.promo-codes.store'), [
                'code' => 'BAD200',
                'type' => 'percentage',
                'value' => 200,
                'applies_to' => 'all',
            ])
            ->assertSessionHasErrors('value');
    }

    public function test_used_promo_code_is_disabled_instead_of_deleted(): void
    {
        $promo = PromoCode::query()->create([
            'code' => 'USED10',
            'type' => 'percentage',
            'value' => 10,
            'applies_to' => 'all',
            'used_count' => 3,
            'active' => true,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.promo-codes.destroy', $promo))
            ->assertRedirect(route('admin.promo-codes.index'))
            ->assertSessionHas('status', '优惠码已有使用记录，已改为停用');

        $this->assertDatabaseHas('promo_codes', [
            'id' => $promo->id,
            'active' => false,
        ]);
    }

    public function test_admin_promo_code_index_can_filter_by_code_and_status(): void
    {
        PromoCode::query()->create([
            'code' => 'VISIBLE10',
            'type' => 'fixed',
            'value' => 10,
            'applies_to' => 'all',
            'active' => true,
        ]);
        PromoCode::query()->create([
            'code' => 'HIDDEN20',
            'type' => 'fixed',
            'value' => 20,
            'applies_to' => 'all',
            'active' => false,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.promo-codes.index', ['code' => 'VISIBLE', 'status' => 'active']))
            ->assertOk()
            ->assertSee('VISIBLE10')
            ->assertDontSee('HIDDEN20');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'promo-admin',
            'email' => 'promo-admin@example.com',
            'password' => Hash::make('password'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function product(): Product
    {
        $group = ProductGroup::query()->create([
            'name' => '云服务器',
            'slug' => 'cloud',
            'sort_order' => 1,
        ]);

        return Product::query()->create([
            'group_id' => $group->id,
            'name' => '基础 VPS',
            'type' => 'vps',
            'pay_type' => 'recurring',
            'pay_method' => 'prepaid',
            'auto_setup' => 'manual',
            'stock_qty' => 10,
        ]);
    }
}
