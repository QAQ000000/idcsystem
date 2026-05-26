<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Modules\Support\Services\KnowledgeBaseService;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KbArticleController extends Controller
{
    public function index(Request $request): View
    {
        $articles = KbArticle::query()
            ->with('category')
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('keyword'), function ($query) use ($request): void {
                $keyword = trim((string) $request->query('keyword'));
                $query->where('title', 'like', "%{$keyword}%");
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.kb.articles.index', [
            'articles' => $articles,
            'categories' => $this->categories(),
        ]);
    }

    public function create(): View
    {
        return view('admin.kb.articles.create', [
            'article' => new KbArticle(['active' => true, 'sort_order' => 0]),
            'categories' => $this->categories(),
        ]);
    }

    public function store(Request $request, KnowledgeBaseService $kb, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $kb);
        $article = KbArticle::query()->create($data);
        $audit->record($request, 'kb_article.create', $article, 'success', $data);

        return redirect()->route('admin.kb.articles.index')->with('status', '知识库文章已创建');
    }

    public function edit(KbArticle $article): View
    {
        return view('admin.kb.articles.edit', [
            'article' => $article,
            'categories' => $this->categories(),
        ]);
    }

    public function update(
        Request $request,
        KbArticle $article,
        KnowledgeBaseService $kb,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $this->validated($request, $kb, $article);
        $article->update($data);
        $audit->record($request, 'kb_article.update', $article, 'success', $data);

        return redirect()->route('admin.kb.articles.index')->with('status', '知识库文章已保存');
    }

    public function destroy(Request $request, KbArticle $article, AdminAuditService $audit): RedirectResponse
    {
        $articleId = $article->id;
        $article->delete();
        $audit->record($request, 'kb_article.delete', null, 'success', ['kb_article_id' => $articleId]);

        return redirect()->route('admin.kb.articles.index')->with('status', '知识库文章已删除');
    }

    private function validated(Request $request, KnowledgeBaseService $kb, ?KbArticle $article = null): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('kb_categories', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', Rule::unique('kb_articles', 'slug')->ignore($article?->id)],
            'content' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $data['slug'] ?: $kb->slug($data['title']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['active'] = $request->boolean('active');

        return $data;
    }

    private function categories()
    {
        return KbCategory::query()->orderBy('sort_order')->orderBy('name')->get();
    }
}
