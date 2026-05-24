<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Credit extends Model
{
    protected $fillable = [
        'client_id', 'type', 'amount', 'balance',
        'description', 'rel_type', 'rel_id',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Modules\User\Models\Client::class);
    }
}