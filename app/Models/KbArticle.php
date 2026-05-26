<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class KbArticle extends Model
{
    protected $fillable = [
        'category_id',
        'title',
        'slug',
        'content',
        'views',
        'helpful_count',
        'not_helpful_count',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'views' => 'integer',
        'helpful_count' => 'integer',
        'not_helpful_count' => 'integer',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (KbArticle $article): void {
            $article->slug = $article->slug ?: Str::slug($article->title);
        });
    }

    public function category()
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }
}
