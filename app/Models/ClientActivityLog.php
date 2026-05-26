<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class ClientActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'action',
        'description',
        'meta',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
