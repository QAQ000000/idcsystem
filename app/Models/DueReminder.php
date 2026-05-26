<?php

namespace App\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;

class DueReminder extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'host_id',
        'days_before',
        'sent_at',
    ];

    protected $casts = [
        'days_before' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function host()
    {
        return $this->belongsTo(Host::class);
    }
}
