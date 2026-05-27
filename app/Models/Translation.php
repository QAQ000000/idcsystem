<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    protected $fillable = [
        'locale',
        'group',
        'key',
        'value',
        'is_translated',
        'translated_by',
    ];

    protected $casts = [
        'is_translated' => 'boolean',
    ];
}
