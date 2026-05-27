<?php

namespace App\Modules\Admin\Models;

use App\Models\Plugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplacePlugin extends Model
{
    protected $fillable = [
        'name',
        'title',
        'description',
        'type',
        'version',
        'author',
        'author_url',
        'download_url',
        'price',
        'downloads_count',
        'rating',
        'reviews_count',
        'screenshots',
        'requirements',
        'is_verified',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'downloads_count' => 'integer',
        'rating' => 'decimal:2',
        'reviews_count' => 'integer',
        'screenshots' => 'array',
        'requirements' => 'array',
        'is_verified' => 'boolean',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(PluginReview::class);
    }

    public function installedPlugin(): ?Plugin
    {
        return Plugin::query()
            ->where('name', $this->name)
            ->where('type', $this->type)
            ->first();
    }
}
