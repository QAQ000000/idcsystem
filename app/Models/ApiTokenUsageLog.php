<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenUsageLog extends Model
{
    protected $fillable = [
        'token_id',
        'endpoint',
        'method',
        'response_code',
        'response_time',
        'requested_at',
    ];

    protected $casts = [
        'response_code' => 'integer',
        'response_time' => 'integer',
        'requested_at' => 'datetime',
    ];

    public function token()
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }
}
