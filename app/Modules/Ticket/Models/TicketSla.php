<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSla extends Model
{
    protected $fillable = [
        'department_id',
        'priority',
        'response_time_minutes',
        'resolution_time_minutes',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'response_time_minutes' => 'integer',
        'resolution_time_minutes' => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(TicketDepartment::class);
    }

    public function logs()
    {
        return $this->hasMany(TicketSlaLog::class, 'sla_id');
    }
}
