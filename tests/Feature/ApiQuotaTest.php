<?php

namespace Tests\Feature;

use App\Models\ApiTokenUsageLog;
use App\Modules\Admin\Services\ApiQuotaService;
use App\Modules\Finance\Models\Currency;
use App\Modules\User\Models\Client;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_request_consumes_quota_and_records_usage_log(): void
    {
        [$client, $plainTextToken, $token] = $this->quotaToken(2);

        $this->withToken($plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '1')
            ->assertJsonPath('data.client.id', $client->id);

        $this->assertSame(1, (int) $token->fresh()->quota_used);
        $this->assertDatabaseHas('api_token_usage_logs', [
            'token_id' => $token->id,
            'endpoint' => 'api/auth/me',
            'method' => 'GET',
            'response_code' => 200,
        ]);
    }

    public function test_api_quota_exceeded_returns_429_without_running_endpoint(): void
    {
        [, $plainTextToken, $token] = $this->quotaToken(1, ['quota_used' => 1]);

        $this->withToken($plainTextToken)
            ->getJson('/api/auth/me')
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'API quota exceeded')
            ->assertJsonPath('quota_limit', 1)
            ->assertJsonPath('quota_used', 1);

        $this->assertTrue((bool) $token->fresh()->quota_exceeded);
        $this->assertDatabaseCount('api_token_usage_logs', 0);
    }

    public function test_api_quota_resets_after_reset_date(): void
    {
        [, $plainTextToken, $token] = $this->quotaToken(3, [
            'quota_used' => 3,
            'quota_reset_date' => today()->subDay()->toDateString(),
            'quota_exceeded' => true,
        ]);

        $this->withToken($plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '2');

        $token->refresh();
        $this->assertSame(1, (int) $token->quota_used);
        $this->assertSame(today()->addDay()->toDateString(), (string) $token->quota_reset_date);
        $this->assertFalse((bool) $token->quota_exceeded);
    }

    public function test_api_quota_service_returns_usage_stats(): void
    {
        [, , $token] = $this->quotaToken(10);
        ApiTokenUsageLog::query()->create([
            'token_id' => $token->id,
            'endpoint' => 'api/auth/me',
            'method' => 'GET',
            'response_code' => 200,
            'response_time' => 40,
            'requested_at' => now(),
        ]);
        ApiTokenUsageLog::query()->create([
            'token_id' => $token->id,
            'endpoint' => 'api/account',
            'method' => 'GET',
            'response_code' => 500,
            'response_time' => 80,
            'requested_at' => now(),
        ]);

        $stats = app(ApiQuotaService::class)->getUsageStats($token->id);

        $this->assertSame(2, $stats['total_requests']);
        $this->assertSame(60.0, $stats['avg_response_time']);
        $this->assertSame(50.0, $stats['success_rate']);
        $this->assertTrue($stats['top_endpoints']->pluck('endpoint')->contains('api/account'));
    }

    public function test_api_quota_alert_command_sends_threshold_alert(): void
    {
        [, , $token] = $this->quotaToken(10, ['quota_used' => 9]);
        $mailer = new class extends MailService {
            public int $calls = 0;

            public function send(string $to, string $subject, string $body, array $options = []): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->instance(MailService::class, $mailer);

        $this->artisan('api-quotas:check-alerts', ['--threshold' => 80])
            ->assertExitCode(0);

        $this->assertSame(1, $mailer->calls);
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'api-quotas:check-alerts',
            'status' => 'success',
        ]);
        $this->assertSame($token->id, PersonalAccessToken::query()->firstOrFail()->id);
    }

    private function quotaToken(int $limit, array $overrides = []): array
    {
        $client = $this->client();
        $newAccessToken = $client->createToken('quota-token', ['account:read']);
        $token = $newAccessToken->accessToken;
        $token->forceFill(array_merge([
            'quota_limit' => $limit,
            'quota_used' => 0,
            'quota_reset_date' => today()->addDay()->toDateString(),
            'quota_exceeded' => false,
        ], $overrides))->save();

        return [$client, $newAccessToken->plainTextToken, $token->fresh()];
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'api-quota-client-' . random_int(1000, 9999),
            'email' => 'api-quota-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }
}
