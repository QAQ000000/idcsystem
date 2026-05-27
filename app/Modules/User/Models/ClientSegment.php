<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class ClientSegment extends Model
{
    protected $fillable = [
        'name',
        'description',
        'type',
        'rules',
        'clients_count',
        'last_calculated_at',
    ];

    protected $casts = [
        'rules' => 'array',
        'clients_count' => 'integer',
        'last_calculated_at' => 'datetime',
    ];

    public function members()
    {
        return $this->hasMany(ClientSegmentMember::class, 'segment_id');
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_segment_members', 'segment_id', 'client_id')
            ->withPivot('added_at');
    }

    public function isDynamic(): bool
    {
        return $this->type === 'dynamic';
    }
}
