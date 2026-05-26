<?php

namespace App\Services;

use App\Models\ClientActivityLog;
use App\Modules\User\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ClientActivityService
{
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|credential|authorization|cookie|session_id|session|bearer|access_key|private_key|signature|sign|key)$/i';

    public function log(Client $client, string $action, string $description, array $meta = []): void
    {
        if (!$client->getKey()) {
            return;
        }

        try {
            ClientActivityLog::query()->create([
                'client_id' => $client->getKey(),
                'action' => Str::limit($action, 255, ''),
                'description' => $this->maskSensitiveText($description),
                'meta' => $this->maskSensitive($meta) ?: null,
                'ip' => $this->currentIp(),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // 活动日志是审计补充，不能反向阻断登录、支付、余额等主流程。
        }
    }

    public function getActivities(Client $client, int $limit = 50): Collection
    {
        $limit = max(1, min(200, $limit));

        return $client->activities()
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    private function currentIp(): ?string
    {
        try {
            return request()?->ip();
        } catch (Throwable) {
            return null;
        }
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
                '/(' . preg_quote($key, '/') . ')\s*([=:])\s*([^\s,;]+)/i',
                '$1$2[FILTERED]',
                $value
            ) ?? $value;
        }

        return Str::limit($value, 2000, '...');
    }
}
