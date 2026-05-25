<?php

namespace App\Modules\User\Services;

use App\Modules\User\Models\Client;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct(?Google2FA $google2fa = null)
    {
        $this->google2fa = $google2fa ?? new Google2FA();
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function qrCodeUrl(Client $client, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name', 'IDC System'),
            (string) $client->email,
            $secret
        );
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, 1);
    }

    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }
}
