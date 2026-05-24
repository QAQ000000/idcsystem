<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class Upgrade extends Model
{
    protected $fillable = [
        'host_id',
        'type',
        'from_product_id',
        'to_product_id',
        'amount',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function host()
    {
        return $this->belongsTo(Host::class);
    }
}
