<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class ClientGroup extends Model
{
    protected $fillable = ['name', 'discount_percent', 'color'];

    protected $casts = [
        'discount_percent' => 'decimal:2',
    ];

    public function clients()
    {
        return $this->hasMany(Client::class, 'group_id');
    }
}