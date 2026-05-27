<?php

namespace App\Http\Middleware;

use App\Models\ApiTokenUsageLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class CheckApiQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (!$token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $token = $this->resetQuotaIfNeeded($token);
        $limit = $token->quota_limit === null ? null : (int) $token->quota_limit;
        if ($limit !== null && (int) $token->quota_used >= $limit) {
            $token->forceFill(['quota_exceeded' => true])->save();

            return response()->json([
                'success' => false,
                'message' => 'API quota exceeded',
                'quota_limit' => $limit,
                'quota_used' => (int) $token->quota_used,
                'quota_reset_at' => $this->resetTimestamp($token),
            ], 429);
        }

        if ($limit !== null) {
            $token->increment('quota_used');
            $token->refresh();
        }

        $startedAt = microtime(true);
        $response = $next($request);
        $responseTime = (int) round((microtime(true) - $startedAt) * 1000);

        ApiTokenUsageLog::query()->create([
            'token_id' => $token->id,
            'endpoint' => $request->path(),
            'method' => strtoupper($request->method()),
            'response_code' => $response->getStatusCode(),
            'response_time' => max(0, $responseTime),
            'requested_at' => now(),
        ]);

        $response->headers->set('X-RateLimit-Limit', $limit === null ? 'unlimited' : (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', $limit === null ? 'unlimited' : (string) max(0, $limit - (int) $token->quota_used));
        $response->headers->set('X-RateLimit-Reset', (string) $this->resetTimestamp($token));

        return $response;
    }

    private function resetQuotaIfNeeded(PersonalAccessToken $token): PersonalAccessToken
    {
        if ($token->quota_limit === null) {
            return $token;
        }

        $resetDate = $token->quota_reset_date ? Carbon::parse($token->quota_reset_date) : null;
        if ($resetDate === null || $resetDate->lte(today())) {
            $token->forceFill([
                'quota_used' => 0,
                'quota_reset_date' => today()->addDay()->toDateString(),
                'quota_exceeded' => false,
            ])->save();

            return $token->fresh();
        }

        return $token;
    }

    private function resetTimestamp(PersonalAccessToken $token): int
    {
        return $token->quota_reset_date
            ? Carbon::parse($token->quota_reset_date)->startOfDay()->timestamp
            : 0;
    }
}
