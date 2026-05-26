<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'order_number', 'status', 'requires_approval', 'amount', 'currency_id',
        'payment_method', 'paid_at', 'promo_code', 'promo_value',
        'invoice_id', 'notes', 'admin_notes',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'promo_value' => 'decimal:2',
        'requires_approval' => 'boolean',
        'paid_at'     => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Modules\User\Models\Client::class)->withTrashed();
    }

    public function hosts()
    {
        return $this->hasMany(Host::class);
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Modules\Finance\Models\Invoice::class);
    }
}
