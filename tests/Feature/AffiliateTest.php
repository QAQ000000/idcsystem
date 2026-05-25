<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\User\Models\AffiliateCommission;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AffiliateService;
use App\Modules\User\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
        $affiliateService->recordPayment($invoice->fresh());

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
}
