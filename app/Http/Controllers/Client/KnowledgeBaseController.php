<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Modules\Support\Services\KnowledgeBaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function index(): View
    {
        return view('theme::kb.index', [
            'categories' => KbCategory::query()
                ->where('active', true)
                ->withCount(['articles' => fn ($query) => $query->where('active', true)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function category(KbCategory $category): View
    {
        abort_unless($category->active, 404);

        return view('theme::kb.category', [
            'category' => $category,
            'articles' => $category->articles()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('title')
                ->paginate(20),
        ]);
    }

    public function article(KbCategory $category, KbArticle $article, KnowledgeBaseService $kb): View
    {
        abort_unless($category->active && $article->active && (int) $article->category_id === (int) $category->id, 404);
        $kb->incrementViews($article);

        return view('theme::kb.article', [
            'category' => $category,
            'article' => $article->fresh('category'),
        ]);
    }

    public function search(Request $request, KnowledgeBaseService $kb): View|JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $articles = $kb->search($query, $request->expectsJson() ? 5 : 20);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $articles->map(fn (KbArticle $article): array => [
                    'title' => $article->title,
                    'url' => route('client.kb.article', [$article->category, $article]),
                    'category' => $article->category?->name,
                ])->values(),
            ]);
        }

        return view('theme::kb.search', [
            'query' => $query,
            'articles' => $articles,
        ]);
    }

    public function feedback(Request $request, KbArticle $article, KnowledgeBaseService $kb): RedirectResponse
    {
        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
        ]);

        abort_unless($article->active, 404);
        $kb->markHelpful($article, (bool) $data['helpful']);

        return back()->with('status', '感谢反馈');
    }
}
