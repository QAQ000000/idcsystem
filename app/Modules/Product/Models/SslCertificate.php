<?php

namespace App\Modules\Product\Models;

use App\Modules\Order\Models\Host;
use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class SslCertificate extends Model
{
    protected $fillable = [
        'client_id',
        'host_id',
        'domain',
        'type',
        'status',
        'issue_date',
        'expiry_date',
        'certificate',
        'private_key',
        'ca_bundle',
        'auto_renew',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'private_key' => 'encrypted',
        'auto_renew' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function host()
    {
        return $this->belongsTo(Host::class);
    }
}
