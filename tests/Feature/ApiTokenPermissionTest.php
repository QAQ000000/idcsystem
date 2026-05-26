<?php

namespace Tests\Feature;

use App\Models\ClientActivityLog;
use App\Modules\User\Models\Client;
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

    private function client(): Client
    {
        return Client::query()->create([
            'username' => 'api-token-client-' . random_int(1000, 9999),
            'email' => 'api-token-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
    }
}
