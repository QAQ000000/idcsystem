<?php

namespace App\Modules\Order\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class CancelRequest extends Model
{
    protected $fillable = [
        'client_id',
        'host_id',
        'type',
        'reason',
        'status',
        'admin_notes',
        'approved_at',
        'completed_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function host()
    {
        return $this->belongsTo(Host::class);
    }
}
