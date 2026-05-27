<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = ['client_id', 'name', 'email', 'phone', 'permissions'];

    protected $casts = [
        'phone' => 'encrypted',
        'permissions' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
