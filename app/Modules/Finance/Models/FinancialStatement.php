<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialStatement extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'total_income',
        'total_refund',
        'total_commission',
        'net_income',
        'paid_invoice_count',
        'refund_count',
        'breakdown',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_income' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'net_income' => 'decimal:2',
        'paid_invoice_count' => 'integer',
        'refund_count' => 'integer',
        'breakdown' => 'array',
    ];
}
