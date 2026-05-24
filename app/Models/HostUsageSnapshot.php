<?php

namespace App\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;

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
}
