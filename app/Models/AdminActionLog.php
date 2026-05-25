<?php

namespace App\Models;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AdminActionLog extends Model
{
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|credential|authorization|cookie|session_id|session|bearer|access_key|private_key|key|signature|sign)$/i';

    protected $fillable = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
        'result',
        'payload',
        'error',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (AdminActionLog $log): void {
            $log->payload = static::maskSensitive($log->payload ?? []);
            $log->error = $log->error === null ? null : static::maskSensitiveText($log->error);
            $log->user_agent = $log->user_agent === null ? null : static::maskSensitiveText($log->user_agent);
        });
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
