<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class KbCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (KbCategory $category): void {
            $category->slug = $category->slug ?: Str::slug($category->name);
        });
    }

    public function articles()
    {
        return $this->hasMany(KbArticle::class, 'category_id');
    }
}
