<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Admin\Models\AdminUser;
use App\Jobs\ProcessPaidInvoiceJob;
use App\Modules\User\Models\AffiliateCommission;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AffiliateService;
use App\Modules\User\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AffiliateTest extends TestCase
{
    use RefreshDatabase;

    public function test_affiliate_service_records_signup_payment_and_withdrawal(): void
    {
        Config::set('billing.affiliate_commission_rate', 10);

        $referrer = Client::query()->create([
            'username' => 'referrer',
            'email' => 'referrer@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $affiliateService = app(AffiliateService::class);
        $affiliate = $affiliateService->getOrCreate($referrer);

        $referred = app(AuthService::class)->register([
            'username' => 'referred',
            'email' => 'referred@example.com',
            'password' => 'password123',
            'ref' => $affiliate->code,
        ]);

        $this->assertDatabaseHas('affiliate_commissions', [
            'affiliate_id' => $affiliate->id,
            'referred_client_id' => $referred->id,
            'type' => 'signup',
            'status' => 'approved',
        ]);
        $this->assertSame(1, $affiliate->fresh()->referral_count);

        $referred->update(['status' => 1]);
        $invoice = app(InvoiceService::class)->generate($referred->fresh(), [[
            'type' => 'product',
            'description' => 'VPS',
            'amount' => 100,
        ]]);

        $this->assertTrue(app(InvoiceService::class)->markAsPaid($invoice, 'manual', 'AFF-PAID-1'));
        (new ProcessPaidInvoiceJob($invoice->id))->handle(
            app(\App\Modules\Order\Services\HostService::class),
            app(\App\Modules\Product\Services\DomainService::class),
            app(\App\Modules\Product\Services\SslService::class),
            app(\App\Services\NotificationService::class),
            $affiliateService
        );

        $commission = AffiliateCommission::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('invoice_id', $invoice->id)
            ->where('type', 'payment')
            ->firstOrFail();

        $this->assertSame('10.00', (string) $commission->amount);
        $this->assertSame(1, AffiliateCommission::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('invoice_id', $invoice->id)
            ->where('type', 'payment')
            ->count());

        $this->assertTrue($affiliateService->approve($commission));
        $this->assertSame('10.00', (string) $affiliate->fresh()->balance);

        $this->assertTrue($affiliateService->withdraw($affiliate->fresh(), 10));
        $this->assertSame('0.00', (string) $affiliate->fresh()->balance);
        $this->assertSame('10.00', (string) $affiliate->fresh()->withdrawn);
        $this->assertSame('10.00', (string) $referrer->fresh()->credit);
    }

    public function test_signup_ignores_self_referral_and_unknown_code(): void
    {
        $client = Client::query()->create([
            'username' => 'self-referrer',
            'email' => 'self-referrer@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);

        $affiliate = app(AffiliateService::class)->getOrCreate($client);
        app(AffiliateService::class)->recordSignup($client, $affiliate->code);
        app(AffiliateService::class)->recordSignup($client, 'missing-code');

        $this->assertSame(0, AffiliateCommission::query()->count());
        $this->assertSame(0, $affiliate->fresh()->referral_count);
    }

    public function test_client_can_view_affiliate_page_and_withdraw_approved_balance(): void
    {
        $client = Client::query()->create([
            'username' => 'affiliate-client',
            'email' => 'affiliate-client@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $referred = Client::query()->create([
            'username' => 'affiliate-referred',
            'email' => 'affiliate-referred@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $affiliate = app(AffiliateService::class)->getOrCreate($client);
        $commission = AffiliateCommission::query()->create([
            'affiliate_id' => $affiliate->id,
            'referred_client_id' => $referred->id,
            'amount' => 15,
            'type' => 'payment',
            'status' => 'pending',
        ]);
        app(AffiliateService::class)->approve($commission);

        $this->actingAs($client, 'client')
            ->get(route('client.affiliate'))
            ->assertOk()
            ->assertSee($affiliate->code)
            ->assertSee('15.00');

        $this->actingAs($client, 'client')
            ->post(route('client.affiliate.withdraw'), ['amount' => 15])
            ->assertRedirect(route('client.affiliate'));

        $this->assertSame('15.00', (string) $client->fresh()->credit);
        $this->assertSame('0.00', (string) $affiliate->fresh()->balance);
    }

    public function test_admin_can_manage_affiliate_commissions(): void
    {
        $admin = $this->admin();
        $client = Client::query()->create([
            'username' => 'admin-affiliate-client',
            'email' => 'admin-affiliate-client@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $referred = Client::query()->create([
            'username' => 'admin-affiliate-referred',
            'email' => 'admin-affiliate-referred@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $affiliate = app(AffiliateService::class)->getOrCreate($client);
        $commission = AffiliateCommission::query()->create([
            'affiliate_id' => $affiliate->id,
            'referred_client_id' => $referred->id,
            'amount' => 20,
            'type' => 'payment',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.affiliates.index'))
            ->assertOk()
            ->assertSee('admin-affiliate-client')
            ->assertSee('admin-affiliate-referred');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.affiliate-commissions.approve', $commission))
            ->assertRedirect();

        $this->assertSame('approved', $commission->fresh()->status);
        $this->assertSame('20.00', (string) $affiliate->fresh()->balance);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.affiliates.payout', $affiliate), ['amount' => 20])
            ->assertRedirect();

        $this->assertSame('paid', $commission->fresh()->status);
        $this->assertSame('20.00', (string) $client->fresh()->credit);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'affiliate.commission.approve',
            'result' => 'success',
        ]);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'affiliate.payout',
            'result' => 'success',
        ]);
    }

    public function test_non_affiliate_admin_cannot_view_affiliate_management(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'affiliate-limited-' . random_int(1000, 9999),
            'email' => 'affiliate-limited-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.affiliates.index'))
            ->assertForbidden();
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'affiliate-admin-' . random_int(1000, 9999),
            'email' => 'affiliate-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
