<?php

namespace App\Services;

use App\Models\SystemTaskLog;
use Illuminate\Support\Str;
use Throwable;

class SystemTaskService
{
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|credential|authorization|cookie|session_id|session|bearer|access_key|private_key|key|signature|sign)$/i';

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
            $errorMessage = is_array($result) ? $this->errorMessageFromResult($result, $failedCount) : null;

            $log->update([
                'status' => $errorMessage ? 'failed' : 'success',
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAtFloat) * 1000),
                'output' => $output,
                'error' => $errorMessage,
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedAtFloat) * 1000),
                'output' => $log->output,
                'error' => $this->maskSensitiveText($exception->getMessage()),
            ]);
        }

        return $log->fresh();
    }

    private function errorMessageFromResult(array $result, int $failedCount): ?string
    {
        if ($failedCount > 0) {
            return "{$failedCount} 个子任务失败";
        }

        foreach (['error', 'errors'] as $key) {
            if (!array_key_exists($key, $result) || $result[$key] === null || $result[$key] === '' || $result[$key] === []) {
                continue;
            }

            return $this->stringify($result[$key]) ?: '任务返回错误信息';
        }

        return null;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $this->maskSensitiveText($value);
        }

        if (is_scalar($value)) {
            return $this->maskSensitiveText((string) $value);
        }

        return json_encode($this->maskSensitive($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function maskSensitive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? $this->maskSensitiveText($value) : $value;
        }

        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string) $key)) {
                $value[$key] = '[FILTERED]';

                continue;
            }

            $value[$key] = $this->maskSensitive($item);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1;
    }

    private function maskSensitiveText(string $value): string
    {
        foreach ([
            'password',
            'passwd',
            'secret',
            'token',
            'credential',
            'authorization',
            'cookie',
            'session',
            'bearer',
            'access_key',
            'private_key',
            'signature',
            'sign',
            'key',
        ] as $key) {
            $value = preg_replace(
                '/(' . preg_quote($key, '/') . ')\s*([=:])\s*(?!\[FILTERED\])([^\s,;"\}\]]+)/i',
                '$1$2[FILTERED]',
                $value
            ) ?? $value;
        }

        return Str::limit($value, 2000, '...');
    }
}
