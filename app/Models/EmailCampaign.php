<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    protected $fillable = [
        'name',
        'subject',
        'content',
        'target_groups',
        'status',
        'scheduled_at',
        'sent_at',
        'total_recipients',
        'sent_count',
        'opened_count',
        'clicked_count',
    ];

    protected $casts = [
        'target_groups' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'opened_count' => 'integer',
        'clicked_count' => 'integer',
    ];

    public function recipients()
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'campaign_id');
    }
}
