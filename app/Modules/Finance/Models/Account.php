<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [
        'client_id', 'invoice_id', 'type', 'amount', 'fee',
        'payment_method', 'gateway_trans_id', 'description', 'refunded',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'fee'      => 'decimal:2',
        'refunded' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Modules\User\Models\Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
