<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class CreditScoreLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'client_id',
        'old_score',
        'new_score',
        'reason',
        'event_key',
        'details',
        'created_at',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
