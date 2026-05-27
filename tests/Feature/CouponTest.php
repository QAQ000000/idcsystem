<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Order\Models\Coupon;
use App\Modules\Order\Models\CouponClaim;
use App\Modules\Order\Services\CouponService;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────── Admin CRUD ────────────────────────────

    public function test_admin_can_list_coupons(): void
    {
        $coupon = Coupon::query()->create($this->couponData(['name' => '测试优惠券A']));

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSee('测试优惠券A');
    }

    public function test_admin_can_create_coupon(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.coupons.store'), [
                'name'             => '新优惠券',
                'type'             => 'fixed',
                'value'            => '20.00',
                'min_order_amount' => '0',
                'stock'            => '10',
                'is_active'        => '1',
            ])
            ->assertRedirect(route('admin.coupons.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('coupons', [
            'name'  => '新优惠券',
            'type'  => 'fixed',
            'stock' => 10,
        ]);
    }

    public function test_admin_can_update_coupon(): void
    {
        $coupon = Coupon::query()->create($this->couponData(['name' => '旧名称']));

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.coupons.update', $coupon), [
                'name'             => '新名称',
                'type'             => 'fixed',
                'value'            => '15.00',
                'min_order_amount' => '0',
                'stock'            => '0',
                'is_active'        => '1',
            ])
            ->assertRedirect(route('admin.coupons.index'))
            ->assertSessionHas('status');

        $this->assertSame('新名称', $coupon->fresh()->name);
    }

    public function test_admin_can_delete_coupon(): void
    {
        $coupon = Coupon::query()->create($this->couponData());

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.coupons.destroy', $coupon))
            ->assertRedirect(route('admin.coupons.index'));

        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    // ──────────────────────────── Client claim ────────────────────────────

    public function test_client_can_claim_available_coupon(): void
    {
        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData(['stock' => 5]));

        $this->actingAs($client, 'client')
            ->post(route('client.coupons.claim', $coupon))
            ->assertRedirect(route('client.coupons.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('coupon_claims', [
            'coupon_id' => $coupon->id,
            'client_id' => $client->id,
        ]);
        $this->assertSame(1, $coupon->fresh()->claimed_count);
    }

    public function test_client_cannot_claim_same_coupon_twice(): void
    {
        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData());

        CouponClaim::query()->create([
            'coupon_id'  => $coupon->id,
            'client_id'  => $client->id,
            'claimed_at' => now(),
        ]);

        $this->actingAs($client, 'client')
            ->post(route('client.coupons.claim', $coupon))
            ->assertRedirect(route('client.coupons.index'))
            ->assertSessionHasErrors('coupon');

        $this->assertSame(1, CouponClaim::query()->where('client_id', $client->id)->count());
    }

    public function test_client_cannot_claim_inactive_coupon(): void
    {
        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData(['is_active' => false]));

        $this->actingAs($client, 'client')
            ->post(route('client.coupons.claim', $coupon))
            ->assertRedirect(route('client.coupons.index'))
            ->assertSessionHasErrors('coupon');

        $this->assertDatabaseCount('coupon_claims', 0);
    }

    public function test_client_cannot_claim_out_of_stock_coupon(): void
    {
        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData([
            'stock'         => 2,
            'claimed_count' => 2,
        ]));

        $this->actingAs($client, 'client')
            ->post(route('client.coupons.claim', $coupon))
            ->assertRedirect(route('client.coupons.index'))
            ->assertSessionHasErrors('coupon');

        $this->assertDatabaseCount('coupon_claims', 0);
    }

    // ──────────────────────────── CouponService unit-level ────────────────────────────

    public function test_validate_and_calculate_returns_correct_discount(): void
    {
        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData([
            'type'  => 'percent',
            'value' => '10.00',
        ]));
        $claim = CouponClaim::query()->create([
            'coupon_id'  => $coupon->id,
            'client_id'  => $client->id,
            'claimed_at' => now(),
        ]);

        $result = app(CouponService::class)->validateAndCalculate($claim->id, $client->id, 200.0);

        $this->assertEqualsWithDelta(20.0, $result['discount'], 0.001);
        $this->assertSame($claim->id, $result['claim']->id);
    }

    public function test_validate_and_calculate_rejects_already_used_claim(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData());
        $claim = CouponClaim::query()->create([
            'coupon_id'  => $coupon->id,
            'client_id'  => $client->id,
            'claimed_at' => now(),
            'used_at'    => now(),
            'order_id'   => 999,
        ]);

        app(CouponService::class)->validateAndCalculate($claim->id, $client->id, 100.0);
    }

    public function test_validate_and_calculate_rejects_wrong_product(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData(['product_ids' => [1, 2]]));
        $claim = CouponClaim::query()->create([
            'coupon_id'  => $coupon->id,
            'client_id'  => $client->id,
            'claimed_at' => now(),
        ]);

        app(CouponService::class)->validateAndCalculate($claim->id, $client->id, 100.0, 99);
    }

    public function test_mark_used_sets_used_at_and_order_id(): void
    {
        $client = $this->client();
        $coupon = Coupon::query()->create($this->couponData());
        $claim = CouponClaim::query()->create([
            'coupon_id'  => $coupon->id,
            'client_id'  => $client->id,
            'claimed_at' => now(),
        ]);

        app(CouponService::class)->markUsed($claim, 42);

        $fresh = $claim->fresh();
        $this->assertNotNull($fresh->used_at);
        $this->assertSame(42, $fresh->order_id);
    }

    // ──────────────────────────── Helpers ────────────────────────────

    private function couponData(array $overrides = []): array
    {
        return array_merge([
            'name'             => '测试券 ' . random_int(1000, 9999),
            'type'             => 'fixed',
            'value'            => '10.00',
            'min_order_amount' => '0.00',
            'stock'            => 0,
            'claimed_count'    => 0,
            'is_active'        => true,
        ], $overrides);
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username'    => 'coupon-client-' . random_int(1000, 9999),
            'email'       => 'coupon-client-' . random_int(1000, 9999) . '@example.com',
            'password'    => Hash::make('client123456'),
            'status'      => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'coupon-admin-' . random_int(1000, 9999),
            'email'    => 'coupon-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status'   => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
