<?php

namespace App\Modules\Support\Services;

use App\Models\KbArticle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class KnowledgeBaseService
{
    public function search(string $query, int $limit = 10): Collection
    {
        $keyword = trim($query);
        if ($keyword === '') {
            return new Collection();
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';

        return KbArticle::query()
            ->with('category')
            ->where('active', true)
            ->whereHas('category', fn ($category) => $category->where('active', true))
            ->where(function ($builder) use ($like): void {
                $builder->where('title', 'like', $like)
                    ->orWhere('content', 'like', $like);
            })
            ->orderByDesc('views')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }

    public function incrementViews(KbArticle $article): void
    {
        $article->increment('views');
    }

    public function markHelpful(KbArticle $article, bool $helpful): void
    {
        $article->increment($helpful ? 'helpful_count' : 'not_helpful_count');
    }

    public function slug(string $value): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? $slug : substr(sha1($value), 0, 12);
    }
}
