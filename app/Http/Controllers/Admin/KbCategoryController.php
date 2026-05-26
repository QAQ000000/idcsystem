<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KbCategory;
use App\Modules\Support\Services\KnowledgeBaseService;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KbCategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.kb.categories.index', [
            'categories' => KbCategory::query()
                ->withCount('articles')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.kb.categories.create', [
            'category' => new KbCategory(['active' => true, 'sort_order' => 0]),
        ]);
    }

    public function store(Request $request, KnowledgeBaseService $kb, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $kb);
        $category = KbCategory::query()->create($data);
        $audit->record($request, 'kb_category.create', $category, 'success', $data);

        return redirect()->route('admin.kb.categories.index')->with('status', '知识库分类已创建');
    }

    public function edit(KbCategory $category): View
    {
        return view('admin.kb.categories.edit', compact('category'));
    }

    public function update(
        Request $request,
        KbCategory $category,
        KnowledgeBaseService $kb,
        AdminAuditService $audit
    ): RedirectResponse {
        $data = $this->validated($request, $kb, $category);
        $category->update($data);
        $audit->record($request, 'kb_category.update', $category, 'success', $data);

        return redirect()->route('admin.kb.categories.index')->with('status', '知识库分类已保存');
    }

    public function destroy(Request $request, KbCategory $category, AdminAuditService $audit): RedirectResponse
    {
        if ($category->articles()->exists()) {
            return redirect()->route('admin.kb.categories.index')->with('error', '该分类下仍有文章，不能删除');
        }

        $categoryId = $category->id;
        $category->delete();
        $audit->record($request, 'kb_category.delete', null, 'success', ['kb_category_id' => $categoryId]);

        return redirect()->route('admin.kb.categories.index')->with('status', '知识库分类已删除');
    }

    private function validated(Request $request, KnowledgeBaseService $kb, ?KbCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('kb_categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $data['slug'] ?: $kb->slug($data['name']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
