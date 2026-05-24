<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class Host extends Model
{
    protected $fillable = [
        'client_id', 'order_id', 'product_id', 'server_id',
        'domain', 'username', 'password', 'billing_cycle',
        'first_payment_amount', 'recurring_amount',
        'registered_at', 'next_due_date', 'next_invoice_date', 'termination_date',
        'status', 'auto_renew', 'suspend_reason', 'notes', 'admin_notes',
    ];

    protected $casts = [
        'first_payment_amount' => 'decimal:2',
        'recurring_amount'     => 'decimal:2',
        'auto_renew'           => 'boolean',
        'registered_at'        => 'datetime',
        'next_due_date'        => 'datetime',
        'next_invoice_date'    => 'datetime',
        'termination_date'     => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Modules\User\Models\Client::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }
}