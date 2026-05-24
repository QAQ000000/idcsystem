<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class ClientOauth extends Model
{
    protected $table = 'client_oauth';

    protected $fillable = [
        'client_id', 'provider', 'provider_user_id',
        'access_token', 'refresh_token', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}