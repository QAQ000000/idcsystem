<?php

namespace App\Modules\User\Models;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    protected $fillable = [
        'affiliate_id',
        'referred_client_id',
        'invoice_id',
        'amount',
        'type',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function referredClient()
    {
        return $this->belongsTo(Client::class, 'referred_client_id')->withTrashed();
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
