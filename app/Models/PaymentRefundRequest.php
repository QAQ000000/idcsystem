<?php

namespace App\Models;

use App\Modules\Finance\Models\Account;
use Illuminate\Database\Eloquent\Model;

class PaymentRefundRequest extends Model
{
    protected $fillable = [
        'account_id',
        'invoice_id',
        'gateway',
        'gateway_trans_id',
        'amount',
        'status',
        'error',
        'gateway_refund_succeeded_at',
        'finished_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_refund_succeeded_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
