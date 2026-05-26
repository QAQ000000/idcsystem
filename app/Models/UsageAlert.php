<?php

namespace App\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;

class UsageAlert extends Model
{
    public const METRICS = ['cpu', 'memory', 'disk', 'bandwidth'];

    protected $fillable = [
        'host_id',
        'metric',
        'threshold',
        'active',
        'last_triggered_at',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    public function host()
    {
        return $this->belongsTo(Host::class);
    }

    public function logs()
    {
        return $this->hasMany(UsageAlertLog::class, 'alert_id');
    }
}
