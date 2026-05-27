<?php

namespace App\Modules\Order\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class CouponClaim extends Model
{
    protected $fillable = ['coupon_id', 'client_id', 'claimed_at', 'used_at', 'order_id'];

    protected $casts = [
        'claimed_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
