<?php

namespace App\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;

class HostActionLog extends Model
{
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
}
