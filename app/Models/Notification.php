<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['client_id', 'type', 'title', 'content', 'data', 'read', 'read_at'];

    protected $casts = [
        'data' => 'array',
        'read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
