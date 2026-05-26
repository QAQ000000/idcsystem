<?php

namespace App\Modules\Order\Models;

use App\Modules\Product\Models\ProductAddon;
use Illuminate\Database\Eloquent\Model;

class HostAddon extends Model
{
    protected $fillable = [
        'host_id',
        'addon_id',
        'price',
        'billing_cycle',
        'status',
        'next_due_date',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'next_due_date' => 'datetime',
    ];

    public function host()
    {
        return $this->belongsTo(Host::class);
    }

    public function addon()
    {
        return $this->belongsTo(ProductAddon::class, 'addon_id');
    }
}
