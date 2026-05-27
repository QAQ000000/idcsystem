<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingAutomationLog extends Model
{
    protected $fillable = [
        'execution_id',
        'step_index',
        'action',
        'status',
        'message',
        'executed_at',
    ];

    protected $casts = [
        'step_index' => 'integer',
        'executed_at' => 'datetime',
    ];

    public function execution()
    {
        return $this->belongsTo(MarketingAutomationExecution::class, 'execution_id');
    }
}
