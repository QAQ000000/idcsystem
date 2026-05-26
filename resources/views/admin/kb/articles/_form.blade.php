@csrf

<section class="rounded bg-white p-6 shadow-sm">
    <div class="grid gap-4 md:grid-cols-2">
        <label class="block text-sm">
            分类
            <select class="mt-1 w-full rounded border px-3 py-2" name="category_id" required>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) old('category_id', $article->category_id) === (int) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            标题
            <input class="mt-1 w-full rounded border px-3 py-2" name="title" value="{{ old('title', $article->title) }}" required>
        </label>
        <label class="block text-sm">
            Slug
            <input class="mt-1 w-full rounded border px-3 py-2" name="slug" value="{{ old('slug', $article->slug) }}" placeholder="留空自动生成">
        </label>
        <label class="block text-sm">
            排序
            <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="sort_order" min="0" value="{{ old('sort_order', $article->sort_order ?? 0) }}">
        </label>
        <label class="block text-sm md:col-span-2">
            内容
            <textarea class="mt-1 w-full rounded border px-3 py-2 font-mono text-sm" name="content" rows="16" required>{{ old('content', $article->content) }}</textarea>
        </label>
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="active" value="1" @checked(old('active', $article->active ?? true))>
            启用
        </label>
    </div>

    @if ($errors->any())
        <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <button class="mt-6 rounded bg-slate-900 px-4 py-2 text-white">保存文章</button>
</section>
