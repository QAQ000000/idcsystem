<?php

namespace App\Modules\Support\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class MarketingAutomationExecution extends Model
{
    protected $fillable = [
        'automation_id',
        'client_id',
        'current_step',
        'status',
        'context',
        'started_at',
        'next_run_at',
        'completed_at',
    ];

    protected $casts = [
        'current_step' => 'integer',
        'context' => 'array',
        'started_at' => 'datetime',
        'next_run_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function automation()
    {
        return $this->belongsTo(MarketingAutomation::class, 'automation_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function logs()
    {
        return $this->hasMany(MarketingAutomationLog::class, 'execution_id');
    }
}
