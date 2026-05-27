<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiIpWhitelist
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $whitelist = $this->whitelist($token?->ip_whitelist ?? null);

        if ($whitelist === []) {
            return $next($request);
        }

        $clientIp = (string) $request->ip();
        foreach ($whitelist as $allowed) {
            if ($this->ipMatches($clientIp, $allowed)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => '当前 IP 不允许访问该 API Token。',
        ], 403);
    }

    private function whitelist(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values(array_filter(array_map('trim', $decoded)))
            : [];
    }

    private function ipMatches(string $clientIp, string $allowed): bool
    {
        if ($allowed === $clientIp) {
            return true;
        }

        if (!str_contains($allowed, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $allowed, 2);
        if (!is_numeric($bits)) {
            return false;
        }

        $clientPacked = @inet_pton($clientIp);
        $subnetPacked = @inet_pton($subnet);
        if ($clientPacked === false || $subnetPacked === false || strlen($clientPacked) !== strlen($subnetPacked)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($clientPacked) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($clientPacked, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;

        return (ord($clientPacked[$bytes]) & $mask) === (ord($subnetPacked[$bytes]) & $mask);
    }
}
