<?php

namespace Tests\Feature;

use App\Modules\User\Models\Affiliate;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AffiliateLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_affiliate_click_is_tracked_from_referral_query(): void
    {
        $affiliate = app(AffiliateService::class)->getOrCreate($this->client('referrer', 'referrer@example.com'));

        $this->get(route('client.register', ['ref' => $affiliate->code]))
            ->assertOk();

        $this->assertDatabaseHas('affiliate_link_clicks', [
            'affiliate_id' => $affiliate->id,
        ]);
        $this->assertSame(1, (int) $affiliate->fresh()->total_clicks);
    }

    public function test_leaderboard_orders_by_clicks_referrals_and_commission(): void
    {
        $top = Affiliate::query()->create([
            'client_id' => $this->client('top-affiliate', 'top-affiliate@example.com')->id,
            'code' => 'TOPAFF',
            'status' => 'active',
            'balance' => 50,
            'withdrawn' => 20,
            'referral_count' => 5,
            'total_signups' => 5,
            'total_clicks' => 100,
        ]);
        Affiliate::query()->create([
            'client_id' => $this->client('low-affiliate', 'low-affiliate@example.com')->id,
            'code' => 'LOWAFF',
            'status' => 'active',
            'balance' => 10,
            'withdrawn' => 0,
            'referral_count' => 1,
            'total_signups' => 1,
            'total_clicks' => 5,
        ]);

        $service = app(AffiliateService::class);

        $this->assertSame($top->id, $service->getLeaderboard('commission', 1)->first()->id);
        $this->assertSame($top->id, $service->getLeaderboard('referrals', 1)->first()->id);
        $this->assertSame($top->id, $service->getLeaderboard('clicks', 1)->first()->id);
    }

    public function test_client_can_view_affiliate_leaderboard(): void
    {
        $client = $this->client('leaderboard-client', 'leaderboard-client@example.com');
        app(AffiliateService::class)->getOrCreate($client);

        $this->actingAs($client, 'client')
            ->get(route('client.affiliate.leaderboard'))
            ->assertOk()
            ->assertSee('推介排行榜');
    }

    private function client(string $username, string $email): Client
    {
        return Client::query()->create([
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
    }
}
