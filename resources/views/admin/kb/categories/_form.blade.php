@csrf

<section class="rounded bg-white p-6 shadow-sm">
    <div class="grid gap-4 md:grid-cols-2">
        <label class="block text-sm">
            名称
            <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name', $category->name) }}" required>
        </label>
        <label class="block text-sm">
            Slug
            <input class="mt-1 w-full rounded border px-3 py-2" name="slug" value="{{ old('slug', $category->slug) }}" placeholder="留空自动生成">
        </label>
        <label class="block text-sm md:col-span-2">
            描述
            <textarea class="mt-1 w-full rounded border px-3 py-2" name="description" rows="3">{{ old('description', $category->description) }}</textarea>
        </label>
        <label class="block text-sm">
            排序
            <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="sort_order" min="0" value="{{ old('sort_order', $category->sort_order ?? 0) }}">
        </label>
        <label class="mt-7 inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="active" value="1" @checked(old('active', $category->active ?? true))>
            启用
        </label>
    </div>

    @if ($errors->any())
        <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <button class="mt-6 rounded bg-slate-900 px-4 py-2 text-white">保存分类</button>
</section>
