<?php

namespace App\Modules\Product\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $fillable = [
        'client_id',
        'domain',
        'tld',
        'status',
        'registration_date',
        'expiry_date',
        'auto_renew',
        'whois_privacy',
        'nameservers',
        'registrar',
    ];

    protected $casts = [
        'registration_date' => 'date',
        'expiry_date' => 'date',
        'auto_renew' => 'boolean',
        'whois_privacy' => 'boolean',
        'nameservers' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
