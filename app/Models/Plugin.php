<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    protected $fillable = ['name', 'title', 'type', 'version', 'author', 'description', 'status', 'config'];

    protected $casts = [
        'config' => 'array',
        'status' => 'integer',
    ];
}