<?php

namespace App\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HostActionLog extends Model
{
    private const SENSITIVE_KEY_PATTERN = '/(password|secret|token|credential|access_key|private_key|key|signature|sign)$/i';

    protected $fillable = [
        'host_id',
        'action',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function host()
    {
        return $this->belongsTo(Host::class);
    }

    protected static function booted(): void
    {
        static::saving(function (HostActionLog $log): void {
            $log->message = $log->message === null ? null : static::maskSensitiveText($log->message);
            $log->meta = static::maskSensitive($log->meta ?? []);
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
            'secret',
            'token',
            'credential',
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
