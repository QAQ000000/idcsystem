<?php

namespace App\Models;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $fillable = [
        'admin_user_id',
        'type',
        'file_path',
        'status',
        'total_rows',
        'success_count',
        'failed_count',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }
}
