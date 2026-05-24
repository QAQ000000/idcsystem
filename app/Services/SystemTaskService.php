<?php

namespace App\Services;

use App\Models\SystemTaskLog;
use Throwable;

class SystemTaskService
{
    public function run(string $taskName, callable $callback): SystemTaskLog
    {
        $startedAt = now();
        $startedAtFloat = microtime(true);

        $log = SystemTaskLog::query()->create([
            'task_name' => $taskName,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            $result = $callback();
            $output = $this->stringify($result);
            $failedCount = is_array($result) ? (int) ($result['failed'] ?? 0) : 0;

            $log->update([
                'status' => $failedCount > 0 ? 'failed' : 'success',
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAtFloat) * 1000),
                'output' => $output,
                'error' => $failedCount > 0 ? "{$failedCount} 个子任务失败" : null,
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAtFloat) * 1000),
                'output' => $log->output,
                'error' => $exception->getMessage(),
            ]);
        }

        return $log->fresh();
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
