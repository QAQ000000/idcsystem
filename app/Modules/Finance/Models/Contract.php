<?php

namespace App\Modules\Finance\Models;

use App\Modules\Order\Models\Order;
use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'order_id',
        'title',
        'content',
        'status',
        'signed_at',
        'sign_ip',
        'admin_notes',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
