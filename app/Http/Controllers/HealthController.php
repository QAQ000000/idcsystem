<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');

            return [
                'status' => 'ok',
                'message' => 'database connection is healthy',
            ];
        } catch (Throwable $exception) {
            return $this->errorCheck($exception);
        }
    }

    private function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return [
                'status' => 'ok',
                'message' => 'redis connection is healthy',
            ];
        } catch (Throwable $exception) {
            return $this->errorCheck($exception);
        }
    }

    private function checkQueue(): array
    {
        try {
            if (!Schema::hasTable('failed_jobs')) {
                return [
                    'status' => 'error',
                    'message' => 'failed_jobs table is missing',
                ];
            }

            $recentFailures = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();

            return [
                'status' => $recentFailures === 0 ? 'ok' : 'error',
                'message' => $recentFailures === 0
                    ? 'no failed jobs in the last hour'
                    : "{$recentFailures} failed jobs in the last hour",
                'recent_failed_jobs' => $recentFailures,
            ];
        } catch (Throwable $exception) {
            return $this->errorCheck($exception);
        }
    }

    private function checkStorage(): array
    {
        try {
            $path = storage_path();
            $free = disk_free_space($path);
            $total = disk_total_space($path);

            if ($free === false || $total === false || $total <= 0) {
                return [
                    'status' => 'error',
                    'message' => 'storage disk space could not be determined',
                ];
            }

            $freePercent = round(($free / $total) * 100, 2);

            return [
                'status' => $freePercent >= 5 ? 'ok' : 'error',
                'message' => $freePercent >= 5
                    ? 'storage has sufficient free space'
                    : 'storage free space is below 5%',
                'free_bytes' => $free,
                'total_bytes' => $total,
                'free_percent' => $freePercent,
            ];
        } catch (Throwable $exception) {
            return $this->errorCheck($exception);
        }
    }

    private function errorCheck(Throwable $exception): array
    {
        return [
            'status' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}
