<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomReport extends Model
{
    protected $fillable = [
        'name',
        'description',
        'type',
        'query',
        'config',
        'columns',
        'schedule',
        'recipients',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
        'columns' => 'array',
        'recipients' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CustomReportExecution::class);
    }
}
