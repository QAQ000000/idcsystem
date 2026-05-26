<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class PrivacyPolicyConsent extends Model
{
    public $timestamps = false;

    protected $fillable = ['client_id', 'policy_version', 'ip', 'consented_at'];

    protected $casts = [
        'consented_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
