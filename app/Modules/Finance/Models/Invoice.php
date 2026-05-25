<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'invoice_number', 'subtotal', 'tax', 'tax_rate',
        'credit_used', 'total', 'status', 'payment_method',
        'due_date', 'paid_at', 'notes',
    ];

    protected $casts = [
        'subtotal'    => 'decimal:2',
        'tax'         => 'decimal:2',
        'tax_rate'    => 'decimal:2',
        'credit_used' => 'decimal:2',
        'total'       => 'decimal:2',
        'due_date'    => 'datetime',
        'paid_at'     => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Modules\User\Models\Client::class)->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function receipts()
    {
        return $this->hasMany(InvoiceReceipt::class);
    }

    public function order()
    {
        return $this->hasOne(\App\Modules\Order\Models\Order::class);
    }
}
