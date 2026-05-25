<?php

namespace App\Modules\User\Services;

use App\Models\ClientLoginLog;
use App\Modules\User\Models\Client;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

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
}
