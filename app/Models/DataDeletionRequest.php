<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class DataDeletionRequest extends Model
{
    protected $fillable = ['client_id', 'reason', 'status', 'admin_notes', 'approved_at', 'completed_at'];

    protected $casts = [
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
