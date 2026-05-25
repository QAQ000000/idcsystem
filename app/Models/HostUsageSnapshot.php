<?php

namespace App\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HostUsageSnapshot extends Model
{
    protected $fillable = [
        'host_id',
        'cpu',
        'memory',
        'disk',
        'bandwidth',
        'collected_at',
        'error',
    ];

    protected $casts = [
        'cpu' => 'decimal:2',
        'memory' => 'decimal:2',
        'disk' => 'decimal:2',
        'bandwidth' => 'decimal:2',
        'collected_at' => 'datetime',
    ];

    public function host()
    {
        return $this->belongsTo(Host::class);
    }

    protected static function booted(): void
    {
        static::saving(function (HostUsageSnapshot $snapshot): void {
            $snapshot->error = $snapshot->error === null ? null : static::maskSensitiveText($snapshot->error);
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

        return Str::limit($value, 2000, '...');
    }
}
