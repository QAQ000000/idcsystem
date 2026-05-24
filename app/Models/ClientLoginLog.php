<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class ClientLoginLog extends Model
{
    protected $fillable = ['client_id', 'ip', 'user_agent', 'logged_in_at'];

    protected $casts = [
        'logged_in_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
