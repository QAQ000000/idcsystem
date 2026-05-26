@extends('layouts.admin')

@section('title', '知识库文章')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">知识库文章</h1>
            <p class="mt-1 text-sm text-slate-500">维护客户自助查询的 FAQ 和帮助文档。</p>
        </div>
        <div class="flex gap-2">
            <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.kb.categories.index') }}">分类管理</a>
            <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.kb.articles.create') }}">新建文章</a>
        </div>
    </div>

    <section class="mb-6 rounded bg-white p-5 shadow-sm">
        <form method="get" action="{{ route('admin.kb.articles.index') }}" class="grid gap-4 md:grid-cols-4">
            <input class="rounded border px-3 py-2 text-sm" name="keyword" value="{{ request('keyword') }}" placeholder="搜索标题">
            <select class="rounded border px-3 py-2 text-sm" name="category_id">
                <option value="">全部分类</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
            <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">筛选</button>
            <a class="rounded border px-4 py-2 text-center text-sm" href="{{ route('admin.kb.articles.index') }}">重置</a>
        </form>
    </section>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">标题</th>
                    <th class="px-4 py-3">分类</th>
                    <th class="px-4 py-3">浏览</th>
                    <th class="px-4 py-3">反馈</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($articles as $article)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $article->title }}</div>
                            <div class="text-xs text-slate-500">{{ $article->slug }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $article->category?->name ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $article->views }}</td>
                        <td class="px-4 py-3">{{ $article->helpful_count }} / {{ $article->not_helpful_count }}</td>
                        <td class="px-4 py-3">{{ $article->active ? '启用' : '停用' }}</td>
                        <td class="space-x-3 px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.kb.articles.edit', $article) }}">编辑</a>
                            <form class="inline" method="post" action="{{ route('admin.kb.articles.destroy', $article) }}" onsubmit="return confirm('确定删除该文章？')">
                                @csrf
                                @method('delete')
                                <button class="text-red-600">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无文章</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $articles->links() }}</div>
@endsection
