<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'file_path', 'file_size', 'status', 'error_message', 'created_at'];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];
}
