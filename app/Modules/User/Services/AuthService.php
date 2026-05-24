<?php

namespace App\Modules\User\Services;

use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    public function register(array $data): Client
    {
        $clientService = new ClientService();
        return $clientService->create($data);
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

        $client->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        return $client;
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
}