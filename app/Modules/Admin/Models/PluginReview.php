<?php

namespace App\Modules\Admin\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginReview extends Model
{
    protected $fillable = [
        'marketplace_plugin_id',
        'client_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function marketplacePlugin(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlugin::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
