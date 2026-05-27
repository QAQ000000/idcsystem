<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'type', 'value', 'min_order_amount',
        'product_ids', 'stock', 'claimed_count', 'starts_at', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'product_ids'      => 'array',
        'starts_at'        => 'datetime',
        'expires_at'       => 'datetime',
        'is_active'        => 'boolean',
        'value'            => 'decimal:2',
        'min_order_amount' => 'decimal:2',
    ];

    public function claims()
    {
        return $this->hasMany(CouponClaim::class);
    }

    public function isAvailable(): bool
    {
        if (!$this->is_active) return false;
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->expires_at && $now->gt($this->expires_at)) return false;
        if ($this->stock > 0 && $this->claimed_count >= $this->stock) return false;
        return true;
    }
}
