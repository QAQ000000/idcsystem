<?php

namespace App\Modules\User\Services;

use App\Models\ClientLoginLog;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientOauth;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AuthService
{
    public const TWO_FACTOR_SESSION_KEY = 'client_2fa_pending_id';

    public function register(array $data): Client
    {
        $clientService = new ClientService();
        $client = $clientService->create($data);
        $this->sendEmailVerification($client);

        return $client;
    }

    public function login(string $email, string $password): ?Client
    {
        $client = Client::where('email', $email)->first();

        if (!$client || !Hash::check($password, $client->password)) {
            return null;
        }

        if (!$client->isActive()) {
            return null;
        }

        return $client;
    }

    public function loginWithOAuth(string $provider, array $userInfo): Client
    {
        return DB::transaction(function () use ($provider, $userInfo) {
            $providerUserId = trim((string) ($userInfo['id'] ?? $userInfo['openid'] ?? $userInfo['unionid'] ?? ''));
            if ($providerUserId === '') {
                throw new \InvalidArgumentException('第三方账号标识缺失。');
            }

            $oauth = ClientOauth::query()
                ->where('provider', $provider)
                ->where('provider_user_id', $providerUserId)
                ->with('client')
                ->first();

            if ($oauth?->client) {
                $oauth->update($this->oauthTokenPayload($userInfo));

                return $oauth->client;
            }

            $email = $this->oauthEmail($provider, $providerUserId, $userInfo);
            $client = Client::query()->firstOrCreate(
                ['email' => $email],
                [
                    'username' => $this->uniqueOauthUsername($provider, $userInfo),
                    'password' => Hash::make(Str::random(40)),
                    'status' => 1,
                    'email_verified_at' => now(),
                ]
            );

            if (!$client->isActive()) {
                $client->update(['status' => 1, 'email_verified_at' => $client->email_verified_at ?? now()]);
            }

            ClientOauth::query()->updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => $providerUserId],
                ['client_id' => $client->id] + $this->oauthTokenPayload($userInfo)
            );

            return $client->fresh();
        });
    }

    public function recordLogin(Client $client): void
    {
        $client->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        ClientLoginLog::query()->create([
            'client_id' => $client->id,
            'ip' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'logged_in_at' => now(),
        ]);
    }

    public function createToken(Client $client, string $deviceName = 'web'): string
    {
        return $client->createToken($deviceName)->plainTextToken;
    }

    public function logout(Client $client): void
    {
        $client->tokens()->delete();
    }

    public function verifyEmail(Client $client): bool
    {
        return $client->update(['email_verified_at' => now(), 'status' => 1]);
    }

    public function sendEmailVerification(Client $client): bool
    {
        if ($client->email_verified_at !== null) {
            return false;
        }

        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addDay(),
            [
                'id' => $client->id,
                'hash' => sha1((string) $client->email),
            ]
        );

        $result = app(NotificationService::class)->notifyClient($client, 'email_verification', [
            'client_name' => $client->username,
            'verify_url' => $verifyUrl,
        ]);

        return ($result['mail'] ?? false) === true;
    }

    private function oauthEmail(string $provider, string $providerUserId, array $userInfo): string
    {
        $email = trim((string) ($userInfo['email'] ?? ''));

        return $email !== '' ? $email : $provider . '_' . sha1($providerUserId) . '@oauth.local';
    }

    private function uniqueOauthUsername(string $provider, array $userInfo): string
    {
        $base = Str::of((string) ($userInfo['nickname'] ?? $userInfo['name'] ?? $provider . '_user'))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->limit(32, '')
            ->toString();
        $base = $base !== '' ? $base : $provider . '_user';
        $username = $base;
        $suffix = 1;

        while (Client::query()->where('username', $username)->exists()) {
            $username = Str::limit($base, 32, '') . '_' . $suffix++;
        }

        return Str::limit($username, 50, '');
    }

    private function oauthTokenPayload(array $userInfo): array
    {
        return [
            'access_token' => $userInfo['access_token'] ?? null,
            'refresh_token' => $userInfo['refresh_token'] ?? null,
            'expires_at' => isset($userInfo['expires_in']) && is_numeric($userInfo['expires_in'])
                ? now()->addSeconds((int) $userInfo['expires_in'])
                : null,
        ];
    }
}
