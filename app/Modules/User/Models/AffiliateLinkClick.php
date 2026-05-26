<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateLinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = ['affiliate_id', 'ip', 'user_agent', 'referer', 'clicked_at'];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }
}
