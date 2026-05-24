<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = [
        'to',
        'subject',
        'body',
        'template',
        'provider',
        'status',
        'success',
        'payload',
        'error',
        'attempts',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'success' => 'boolean',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
