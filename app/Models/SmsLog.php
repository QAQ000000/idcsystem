<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SmsLog extends Model
{
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|credential|authorization|cookie|session_id|session|bearer|access_key|private_key|key|signature|sign)$/i';

    protected $fillable = [
        'phone',
        'template',
        'template_code',
        'content',
        'provider',
        'status',
        'success',
        'payload',
        'error',
        'attempts',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'success' => 'boolean',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (SmsLog $log): void {
            $log->payload = static::maskSensitive($log->payload ?? []);
            $log->error = $log->error === null ? null : static::maskSensitiveText($log->error);
        });
    }

    public function maskedContent(): string
    {
        return static::maskSensitiveText((string) $this->content);
    }

    private static function maskSensitive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? static::maskSensitiveText($value) : $value;
        }

        foreach ($value as $key => $item) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1) {
                $value[$key] = '[FILTERED]';

                continue;
            }

            $value[$key] = static::maskSensitive($item);
        }

        return $value;
    }

    private static function maskSensitiveText(string $value): string
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
