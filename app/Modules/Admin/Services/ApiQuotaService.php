<?php

namespace App\Modules\Admin\Services;

use App\Models\ApiTokenUsageLog;
use App\Services\MailService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class ApiQuotaService
{
    public function getUsageStats(int $tokenId, string $period = 'today'): array
    {
        $baseQuery = ApiTokenUsageLog::query()->where('token_id', $tokenId);
        $this->applyPeriod($baseQuery, $period);

        $total = (clone $baseQuery)->count();
        $success = (clone $baseQuery)->where('response_code', '<', 400)->count();

        return [
            'total_requests' => $total,
            'avg_response_time' => round((float) (clone $baseQuery)->avg('response_time'), 2),
            'success_rate' => round($success / max(1, $total) * 100, 2),
            'top_endpoints' => (clone $baseQuery)
                ->select('endpoint', DB::raw('COUNT(*) as count'))
                ->groupBy('endpoint')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
        ];
    }

    public function checkQuotaAlerts(int $threshold = 80): int
    {
        $sent = 0;
        PersonalAccessToken::query()
            ->whereNotNull('quota_limit')
            ->where('quota_limit', '>', 0)
            ->where('quota_exceeded', false)
            ->chunkById(100, function ($tokens) use ($threshold, &$sent): void {
                foreach ($tokens as $token) {
                    $usagePercent = (int) floor(((int) $token->quota_used / max(1, (int) $token->quota_limit)) * 100);
                    if ($usagePercent >= $threshold && $this->sendQuotaAlert($token, $usagePercent)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    public function sendQuotaAlert(PersonalAccessToken $token, ?int $usagePercent = null): bool
    {
        $client = $token->tokenable;
        if (!$client || empty($client->email) || empty($token->quota_limit)) {
            return false;
        }

        $usagePercent ??= (int) floor(((int) $token->quota_used / max(1, (int) $token->quota_limit)) * 100);

        return app(MailService::class)->send(
            (string) $client->email,
            'API 配额使用提醒',
            "您的 API Token「{$token->name}」今日配额已使用 {$usagePercent}%。",
            ['async' => false]
        );
    }

    private function applyPeriod($query, string $period): void
    {
        match ($period) {
            'week' => $query->whereBetween('requested_at', [now()->subWeek(), now()]),
            'month' => $query->whereBetween('requested_at', [now()->subMonth(), now()]),
            default => $query->whereDate('requested_at', today()),
        };
    }
}
