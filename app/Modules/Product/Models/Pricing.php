<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class Pricing extends Model
{
    protected $fillable = [
        'type', 'rel_id', 'currency_id',
        'monthly', 'monthly_setup', 'quarterly', 'quarterly_setup',
        'semiannually', 'semiannually_setup', 'annually', 'annually_setup',
        'biennially', 'biennially_setup', 'triennially', 'triennially_setup',
        'onetime', 'hourly', 'daily',
    ];

    protected $casts = [
        'monthly'             => 'decimal:2',
        'monthly_setup'       => 'decimal:2',
        'quarterly'           => 'decimal:2',
        'quarterly_setup'     => 'decimal:2',
        'semiannually'        => 'decimal:2',
        'semiannually_setup'  => 'decimal:2',
        'annually'            => 'decimal:2',
        'annually_setup'      => 'decimal:2',
        'biennially'          => 'decimal:2',
        'biennially_setup'    => 'decimal:2',
        'triennially'         => 'decimal:2',
        'triennially_setup'   => 'decimal:2',
        'onetime'             => 'decimal:2',
        'hourly'              => 'decimal:2',
        'daily'               => 'decimal:2',
    ];
}