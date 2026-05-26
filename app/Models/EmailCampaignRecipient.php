<?php

namespace App\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class EmailCampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id',
        'client_id',
        'status',
        'sent_at',
        'opened_at',
        'clicked_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
