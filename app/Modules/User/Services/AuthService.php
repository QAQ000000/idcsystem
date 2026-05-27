<?php

namespace App\Modules\User\Services;

use App\Models\ClientLoginLog;
use App\Models\LoginAttempt;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientOauth;
use App\Services\ClientActivityService;
use App\Services\GdprService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AuthService
{
    public const TWO_FACTOR_SESSION_KEY = 'client_2fa_pending_id';

    private ?string $lastLoginFailureMessage = null;

    public function register(array $data): Client
    {
        $referralCode = (string) ($data['ref'] ?? '');
        $privacyIp = (string) ($data['privacy_ip'] ?? '0.0.0.0');
        unset($data['ref']);
        unset($data['privacy_ip']);

        $clientService = new ClientService();
        $client = $clientService->create($data);
        app(GdprService::class)->recordConsent($client, (string) config('app.privacy_policy_version', '1.0'), $privacyIp);
        app(AffiliateService::class)->recordSignup($client, $referralCode);
        $this->sendEmailVerification($client);
        app(ClientActivityService::class)->log($client, 'auth.registered', '账户注册成功', [
            'email' => $client->email,
            'username' => $client->username,
        ]);
        try {
            app(\App\Modules\Support\Services\MarketingAutomationService::class)->trigger('client.registered', [
                'client_id' => $client->id,
                'email' => $client->email,
                'client_name' => $client->username,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $client;
    }

    public function login(string $email, string $password): ?Client
    {
        $this->lastLoginFailureMessage = null;
        $email = Str::lower(trim($email));
        $client = Client::where('email', $email)->first();

        if (!$client) {
            $this->recordLoginAttempt($email, 'failed', 'account_not_found');

            return null;
        }

        if ($client->locked_until && $client->locked_until->isFuture()) {
            $this->lastLoginFailureMessage = '账户已被锁定，请稍后再试。';
            $this->recordLoginAttempt($email, 'failed', 'account_locked');

            return null;
        }

        if (!Hash::check($password, $client->password)) {
            $this->recordLoginAttempt($email, 'failed', 'invalid_password');
            $this->lockClientAfterTooManyFailures($client);

            return null;
        }

        if (!$client->isActive()) {
            $this->recordLoginAttempt($email, 'failed', 'account_inactive');

            return null;
        }

        // 凭证校验通过后记录安全审计；完整登录日志仍由 recordLogin() 在 Web/API 成功登录后写入。
        $this->notifyIfSuspiciousLogin($client);
        $this->recordLoginAttempt($email, 'success');

        return $client;
    }

    public function lastLoginFailureMessage(): ?string
    {
        return $this->lastLoginFailureMessage;
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

        app(ClientActivityService::class)->log($client, 'auth.login', '登录成功', [
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }

    public function createToken(Client $client, string $deviceName = 'web', array $abilities = ['*']): string
    {
        return $client->createToken($deviceName, $abilities)->plainTextToken;
    }

    public function createTokenCredential(Client $client, string $deviceName = 'web', array $abilities = ['*'], array $ipWhitelist = []): array
    {
        $newAccessToken = $client->createToken($deviceName, $abilities);
        $secret = Str::random(64);
        $newAccessToken->accessToken->forceFill([
            'api_secret' => Crypt::encryptString($secret),
            'ip_whitelist' => $ipWhitelist === [] ? null : json_encode(array_values($ipWhitelist)),
        ])->save();

        return [
            'token' => $newAccessToken->plainTextToken,
            'api_secret' => $secret,
        ];
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

    private function recordLoginAttempt(string $email, string $status, ?string $failureReason = null): void
    {
        LoginAttempt::query()->create([
            'email' => $email,
            'ip' => (string) request()->ip(),
            'user_agent' => request()->userAgent(),
            'status' => $status,
            'failure_reason' => $status === 'failed' ? $failureReason : null,
            'created_at' => now(),
        ]);
    }

    private function lockClientAfterTooManyFailures(Client $client): void
    {
        $failedCount = LoginAttempt::query()
            ->where('email', Str::lower((string) $client->email))
            ->where('status', 'failed')
            ->where('failure_reason', 'invalid_password')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();

        if ($failedCount < 5) {
            return;
        }

        $lockedUntil = now()->addHour();
        $client->forceFill(['locked_until' => $lockedUntil])->save();
        $this->lastLoginFailureMessage = '账户已被锁定，请稍后再试。';

        app(NotificationService::class)->notifyClient($client->fresh(), 'account_locked', [
            'client_name' => $client->username,
            'locked_until' => $lockedUntil->format('Y-m-d H:i:s'),
        ]);
    }

    private function notifyIfSuspiciousLogin(Client $client): void
    {
        $ip = (string) request()->ip();
        $email = Str::lower((string) $client->email);
        $hasCurrentIp = LoginAttempt::query()
            ->where('email', $email)
            ->where('status', 'success')
            ->where('ip', $ip)
            ->exists();

        if ($hasCurrentIp) {
            return;
        }

        $hasOtherIp = LoginAttempt::query()
            ->where('email', $email)
            ->where('status', 'success')
            ->where('ip', '!=', $ip)
            ->exists();

        if (!$hasOtherIp) {
            return;
        }

        app(NotificationService::class)->notifyClient($client, 'suspicious_login', [
            'client_name' => $client->username,
            'ip' => $ip,
            'user_agent' => (string) request()->userAgent(),
        ]);
    }
}
