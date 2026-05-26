<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAssignmentRule extends Model
{
    protected $fillable = [
        'department_id',
        'strategy',
        'admin_user_ids',
        'active',
    ];

    protected $casts = [
        'admin_user_ids' => 'array',
        'active' => 'boolean',
    ];

    public function department()
    {
        return $this->belongsTo(TicketDepartment::class);
    }
}
