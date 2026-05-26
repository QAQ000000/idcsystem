<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class Affiliate extends Model
{
    protected $fillable = [
        'client_id',
        'code',
        'status',
        'balance',
        'withdrawn',
        'total_clicks',
        'total_signups',
        'referral_count',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'withdrawn' => 'decimal:2',
        'total_clicks' => 'integer',
        'total_signups' => 'integer',
        'referral_count' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function commissions()
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function clicks()
    {
        return $this->hasMany(AffiliateLinkClick::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
