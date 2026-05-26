<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class DomainPricing extends Model
{
    protected $fillable = [
        'tld',
        'register_price',
        'renew_price',
        'transfer_price',
        'active',
    ];

    protected $casts = [
        'register_price' => 'decimal:2',
        'renew_price' => 'decimal:2',
        'transfer_price' => 'decimal:2',
        'active' => 'boolean',
    ];
}
