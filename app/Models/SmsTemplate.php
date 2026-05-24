<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    protected $fillable = ['name', 'content', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
