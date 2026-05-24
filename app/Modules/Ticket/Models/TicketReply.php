<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class TicketReply extends Model
{
    protected $fillable = ['ticket_id', 'author_type', 'author_id', 'message', 'attachment'];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}