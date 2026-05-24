<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class TicketDepartment extends Model
{
    protected $fillable = ['name', 'email', 'auto_response', 'allow_client_open', 'require_login', 'sort_order'];

    protected $casts = [
        'allow_client_open' => 'boolean',
        'require_login'     => 'boolean',
    ];

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'department_id');
    }
}