<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class DataExportRequest extends Model
{
    protected $fillable = ['client_id', 'status', 'file_path', 'error_message', 'completed_at'];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
