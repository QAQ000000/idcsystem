<?php

namespace App\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;

class UsageAlertLog extends Model
{
    protected $fillable = [
        'alert_id',
        'host_id',
        'metric',
        'threshold',
        'current_value',
        'triggered_at',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'current_value' => 'float',
        'triggered_at' => 'datetime',
    ];

    public function alert()
    {
        return $this->belongsTo(UsageAlert::class, 'alert_id');
    }

    public function host()
    {
        return $this->belongsTo(Host::class);
    }
}
