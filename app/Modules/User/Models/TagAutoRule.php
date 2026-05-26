<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class TagAutoRule extends Model
{
    protected $fillable = ['client_tag_id', 'condition_type', 'operator', 'threshold', 'active'];

    protected $casts = [
        'threshold' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function tag()
    {
        return $this->belongsTo(ClientTag::class, 'client_tag_id');
    }
}
