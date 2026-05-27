<?php

namespace App\Modules\Admin\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Authenticatable
{
    use HasApiTokens, HasRoles, SoftDeletes;

    protected string $guard_name = 'web';

    protected $fillable = [
        'username', 'email', 'password', 'real_name', 'phone', 'timezone',
        'two_factor_enabled', 'two_factor_secret',
        'assigned_ticket_count', 'status', 'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected $casts = [
        'status'             => 'integer',
        'two_factor_enabled' => 'boolean',
        'assigned_ticket_count' => 'integer',
        'last_login_at'      => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 1;
    }
}
