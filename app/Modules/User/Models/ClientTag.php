<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class ClientTag extends Model
{
    protected $fillable = ['name', 'slug', 'color', 'description', 'system'];

    protected $casts = [
        'system' => 'boolean',
    ];

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_tag_pivot')
            ->withPivot('tagged_at');
    }

    public function autoRules()
    {
        return $this->hasMany(TagAutoRule::class);
    }
}
