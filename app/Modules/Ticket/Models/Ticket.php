<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'ticket_number', 'client_id', 'department_id', 'status_id',
        'assigned_to', 'subject', 'message', 'priority', 'rating',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Modules\User\Models\Client::class)->withTrashed();
    }

    public function department()
    {
        return $this->belongsTo(TicketDepartment::class);
    }

    public function status()
    {
        return $this->belongsTo(TicketStatus::class, 'status_id');
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class);
    }

    public function slaLog()
    {
        return $this->hasOne(TicketSlaLog::class);
    }
}
