<?php

namespace App\Models;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;

class AdminActionLog extends Model
{
    protected $fillable = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
        'result',
        'payload',
        'error',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
