<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class ContractTemplate extends Model
{
    protected $fillable = [
        'name',
        'content',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
