<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingAutomation extends Model
{
    protected $fillable = [
        'name',
        'description',
        'trigger_event',
        'trigger_conditions',
        'steps',
        'is_active',
        'executions_count',
    ];

    protected $casts = [
        'trigger_conditions' => 'array',
        'steps' => 'array',
        'is_active' => 'boolean',
        'executions_count' => 'integer',
    ];

    public function executions()
    {
        return $this->hasMany(MarketingAutomationExecution::class, 'automation_id');
    }
}
