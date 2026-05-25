<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SystemTaskLog extends Model
{
    protected $fillable = [
        'task_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'output',
        'error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (SystemTaskLog $log): void {
            $log->output = $log->output === null ? null : static::maskSensitiveText($log->output);
            $log->error = $log->error === null ? null : static::maskSensitiveText($log->error);
        });
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
                '/(' . preg_quote($key, '/') . ')\s*([=:])\s*(?!\[FILTERED\])([^\s,;"\}\]]+)/i',
                '$1$2[FILTERED]',
                $value
            ) ?? $value;
        }

        return Str::limit($value, 2000, '...');
    }
}
