<?php

namespace App\Modules\User\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'username', 'email', 'password', 'status', 'group_id',
        'company_name', 'phone_code', 'phone', 'country', 'province', 'city', 'address',
        'currency_id', 'credit', 'credit_limit',
        'two_factor_enabled', 'two_factor_secret',
        'email_verified_at', 'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected $casts = [
        'status'             => 'integer',
        'credit'             => 'decimal:2',
        'credit_limit'       => 'decimal:2',
        'two_factor_enabled' => 'boolean',
        'email_verified_at'  => 'datetime',
        'last_login_at'      => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(ClientGroup::class, 'group_id');
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function oauthAccounts()
    {
        return $this->hasMany(ClientOauth::class);
    }

    public function orders()
    {
        return $this->hasMany(\App\Modules\Order\Models\Order::class);
    }

    public function hosts()
    {
        return $this->hasMany(\App\Modules\Order\Models\Host::class);
    }

    public function invoices()
    {
        return $this->hasMany(\App\Modules\Finance\Models\Invoice::class);
    }

    public function tickets()
    {
        return $this->hasMany(\App\Modules\Ticket\Models\Ticket::class);
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }

    public function hasEnoughCredit(float $amount): bool
    {
        return $this->credit >= $amount;
    }
}
