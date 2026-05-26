<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSlaLog extends Model
{
    protected $fillable = [
        'ticket_id',
        'sla_id',
        'response_due_at',
        'resolution_due_at',
        'first_response_at',
        'resolved_at',
        'response_breached',
        'resolution_breached',
    ];

    protected $casts = [
        'response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'response_breached' => 'boolean',
        'resolution_breached' => 'boolean',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function sla()
    {
        return $this->belongsTo(TicketSla::class, 'sla_id');
    }
}
