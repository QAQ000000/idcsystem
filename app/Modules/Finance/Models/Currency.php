<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = ['code', 'prefix', 'suffix', 'exchange_rate', 'is_default'];

    protected $casts = [
        'exchange_rate' => 'decimal:4',
        'is_default'    => 'boolean',
    ];
}