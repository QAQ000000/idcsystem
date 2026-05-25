<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClientLoginLog extends Model
{
    protected $fillable = ['client_id', 'ip', 'user_agent', 'logged_in_at'];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        static::saving(function (ClientLoginLog $log): void {
            $log->user_agent = $log->user_agent === null ? null : static::maskSensitiveText($log->user_agent);
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
                '/(' . preg_quote($key, '/') . ')\s*([=:])\s*([^\s,;]+)/i',
                '$1$2[FILTERED]',
                $value
            ) ?? $value;
        }

        return Str::limit($value, 500, '');
    }
}
