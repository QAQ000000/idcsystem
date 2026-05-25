<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    protected $fillable = [
        'code',
        'type',
        'value',
        'applies_to',
        'product_ids',
        'max_uses',
        'used_count',
        'once_per_client',
        'starts_at',
        'expires_at',
        'active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'product_ids' => 'array',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'once_per_client' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'active' => 'boolean',
    ];
}
