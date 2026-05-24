<?php

namespace App\Models;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'invoice_id',
        'client_id',
        'gateway',
        'amount',
        'status',
        'result',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'result' => 'array',
        'completed_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
