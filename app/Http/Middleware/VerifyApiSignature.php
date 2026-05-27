<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyApiSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->requiresSignature($request)) {
            return $next($request);
        }

        $token = $request->user()?->currentAccessToken();
        $encryptedSecret = $token?->api_secret ?? null;

        if (!$encryptedSecret) {
            return $next($request);
        }

        $signature = (string) $request->header('X-Signature', '');
        $timestamp = (string) $request->header('X-Timestamp', '');

        if ($signature === '' || $timestamp === '') {
            return $this->reject('缺少 API 签名。');
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return $this->reject('API 请求已过期。');
        }

        try {
            $secret = Crypt::decryptString($encryptedSecret);
        } catch (Throwable) {
            return $this->reject('API 签名密钥不可用。');
        }

        $payload = implode('|', [
            strtoupper($request->method()),
            $request->path(),
            $timestamp,
            $request->getContent(),
        ]);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $signature)) {
            return $this->reject('API 签名无效。');
        }

        return $next($request);
    }

    private function requiresSignature(Request $request): bool
    {
        return $request->is([
            'api/account/recharge',
            'api/invoices/*/pay-with-credit',
            'api/hosts/*/renew',
        ]);
    }

    private function reject(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 401);
    }
}
