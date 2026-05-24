<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $fillable = [
        'phone',
        'template',
        'template_code',
        'content',
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
