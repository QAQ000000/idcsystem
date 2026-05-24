<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class TicketStatus extends Model
{
    protected $fillable = ['name', 'color', 'show_client', 'is_default', 'sort_order'];

    protected $casts = [
        'show_client' => 'boolean',
        'is_default'  => 'boolean',
    ];
}