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
        'country_code', 'state_code', 'currency_id', 'locale', 'credit', 'credit_limit',
        'two_factor_enabled', 'two_factor_secret', 'notification_preferences',
        'email_verified_at', 'last_login_at', 'last_login_ip', 'locked_until',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected $casts = [
        'status'             => 'integer',
        'credit'             => 'decimal:2',
        'credit_limit'       => 'decimal:2',
        'two_factor_enabled' => 'boolean',
        'notification_preferences' => 'array',
        'email_verified_at'  => 'datetime',
        'last_login_at'      => 'datetime',
        'locked_until'       => 'datetime',
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

    public function domains()
    {
        return $this->hasMany(\App\Modules\Product\Models\Domain::class);
    }

    public function sslCertificates()
    {
        return $this->hasMany(\App\Modules\Product\Models\SslCertificate::class);
    }

    public function dataExportRequests()
    {
        return $this->hasMany(\App\Models\DataExportRequest::class);
    }

    public function dataDeletionRequests()
    {
        return $this->hasMany(\App\Models\DataDeletionRequest::class);
    }

    public function privacyPolicyConsents()
    {
        return $this->hasMany(\App\Models\PrivacyPolicyConsent::class);
    }

    public function invoices()
    {
        return $this->hasMany(\App\Modules\Finance\Models\Invoice::class);
    }

    public function tickets()
    {
        return $this->hasMany(\App\Modules\Ticket\Models\Ticket::class);
    }

    public function loginLogs()
    {
        return $this->hasMany(\App\Models\ClientLoginLog::class);
    }

    public function activities()
    {
        return $this->hasMany(\App\Models\ClientActivityLog::class);
    }

    public function credits()
    {
        return $this->hasMany(\App\Modules\Finance\Models\Credit::class);
    }

    public function affiliate()
    {
        return $this->hasOne(Affiliate::class);
    }

    public function affiliateCommissions()
    {
        return $this->hasMany(AffiliateCommission::class, 'referred_client_id');
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }

    public function hasEnoughCredit(float $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        return $this->credit >= $amount;
    }

    public function availableCredit(): float
    {
        return round((float) $this->credit + (float) $this->credit_limit, 2);
    }
}
