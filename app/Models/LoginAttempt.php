<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email',
        'ip',
        'user_agent',
        'status',
        'failure_reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (LoginAttempt $attempt): void {
            $attempt->email = Str::lower(trim((string) $attempt->email));
            $attempt->user_agent = $attempt->user_agent === null ? null : Str::limit($attempt->user_agent, 500, '');
        });
    }
}
