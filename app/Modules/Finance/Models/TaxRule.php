<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRule extends Model
{
    protected $fillable = [
        'name',
        'country_code',
        'state_code',
        'rate',
        'active',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'active' => 'boolean',
    ];
}
