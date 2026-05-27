<?php

namespace Tests\Feature;

use App\Models\ClientActivityLog;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiTokenPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_token_ability_is_enforced(): void
    {
        $client = $this->client();
        $accountToken = $client->createToken('account', ['account:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $accountToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id);
    }

    public function test_api_token_missing_ability_is_rejected(): void
    {
        $client = $this->client();
        $productsToken = $client->createToken('products-only', ['products:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $productsToken)
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => '权限不足']);
    }

    public function test_wildcard_token_remains_backward_compatible(): void
    {
        $client = $this->client();
        $token = $client->createToken('legacy')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id);
    }

    public function test_api_requests_are_logged_with_token_id(): void
    {
        $client = $this->client();
        $accessToken = $client->createToken('account', ['account:read']);

        $this->withToken($accessToken->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertDatabaseHas('client_activity_logs', [
            'client_id' => $client->id,
            'action' => 'api.request',
            'description' => 'GET api/auth/me',
        ]);
        $this->assertSame($accessToken->accessToken->id, ClientActivityLog::query()->where('action', 'api.request')->first()->meta['token_id']);
    }

    public function test_client_can_create_scoped_token_from_center(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->post(route('client.api-tokens.store'), [
                'name' => 'read token',
                'abilities' => ['products:read', 'account:read'],
            ])
            ->assertRedirect(route('client.api-tokens.index'))
            ->assertSessionHas('plain_text_token');

        $token = $client->tokens()->first();
        $this->assertSame(['products:read', 'account:read'], $token->abilities);
    }

    public function test_client_center_token_returns_api_secret_and_stores_ip_whitelist(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->post(route('client.api-tokens.store'), [
                'name' => 'secured token',
                'abilities' => ['products:read'],
                'ip_whitelist' => "127.0.0.1\n10.0.0.0/8",
            ])
            ->assertRedirect(route('client.api-tokens.index'))
            ->assertSessionHas('plain_text_token')
            ->assertSessionHas('api_secret');

        $token = $client->tokens()->first();
        $this->assertSame(['127.0.0.1', '10.0.0.0/8'], json_decode($token->ip_whitelist, true));
        $this->assertNotEmpty($token->api_secret);
    }

    public function test_request_body_size_limit_rejects_large_api_requests(): void
    {
        config(['sanctum.max_request_body_bytes' => 10]);

        $this
            ->postJson('/api/auth/login', ['email' => 'client@example.com', 'password' => 'secret'])
            ->assertStatus(413)
            ->assertJson(['success' => false]);
    }

    public function test_api_token_ip_whitelist_rejects_unlisted_ip(): void
    {
        $client = $this->client();
        $credential = app(AuthService::class)->createTokenCredential($client, 'ip-limited', ['account:read'], ['10.10.10.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withToken($credential['token'])
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => '当前 IP 不允许访问该 API Token。']);
    }

    public function test_api_token_ip_whitelist_allows_matching_ip(): void
    {
        $client = $this->client();
        $credential = app(AuthService::class)->createTokenCredential($client, 'ip-limited', ['account:read'], ['127.0.0.1']);

        $this->withToken($credential['token'])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id);
    }

    public function test_sensitive_api_requires_signature_for_secured_tokens(): void
    {
        $client = $this->client();
        $credential = app(AuthService::class)->createTokenCredential($client, 'signed', ['account:write']);

        $this->withToken($credential['token'])
            ->postJson('/api/account/recharge', ['amount' => 88])
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => '缺少 API 签名。']);
    }

    public function test_sensitive_api_rejects_expired_signature(): void
    {
        $client = $this->client();
        $credential = app(AuthService::class)->createTokenCredential($client, 'signed', ['account:write']);
        $timestamp = (string) now()->subMinutes(10)->timestamp;
        $body = json_encode(['amount' => 88]);
        $signature = hash_hmac('sha256', 'POST|api/account/recharge|' . $timestamp . '|' . $body, $credential['api_secret']);

        $this->withToken($credential['token'])
            ->withHeaders(['X-Timestamp' => $timestamp, 'X-Signature' => $signature])
            ->postJson('/api/account/recharge', ['amount' => 88])
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'API 请求已过期。']);
    }

    public function test_sensitive_api_accepts_valid_signature(): void
    {
        $client = $this->client();
        $credential = app(AuthService::class)->createTokenCredential($client, 'signed', ['account:write']);
        $timestamp = (string) now()->timestamp;
        $body = json_encode(['amount' => 88]);
        $signature = hash_hmac('sha256', 'POST|api/account/recharge|' . $timestamp . '|' . $body, $credential['api_secret']);

        $this->withToken($credential['token'])
            ->withHeaders(['X-Timestamp' => $timestamp, 'X-Signature' => $signature])
            ->postJson('/api/account/recharge', ['amount' => 88])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 88);
    }

    public function test_legacy_token_without_api_secret_keeps_sensitive_api_compatible(): void
    {
        $client = $this->client();
        $token = $client->createToken('legacy', ['invoices:write'])->plainTextToken;
        $invoice = $this->invoice($client, 'INV-LEGACY-SIGN');
        $client->update(['credit' => 120]);

        $this->withToken($token)
            ->postJson("/api/invoices/{$invoice->id}/pay-with-credit")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'api-token-client-' . random_int(1000, 9999),
            'email' => 'api-token-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function invoice(Client $client, string $number): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => $number,
            'subtotal' => 70,
            'tax' => 0,
            'total' => 70,
            'status' => 'Unpaid',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'type' => 'product',
            'description' => 'API secured invoice item',
            'amount' => 70,
        ]);

        return $invoice;
    }
}
