@extends('layouts.admin')

@section('title', '知识库分类')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">知识库分类</h1>
            <p class="mt-1 text-sm text-slate-500">维护 FAQ 和帮助文档的分类。</p>
        </div>
        <div class="flex gap-2">
            <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.kb.articles.index') }}">文章管理</a>
            <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.kb.categories.create') }}">新建分类</a>
        </div>
    </div>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">Slug</th>
                    <th class="px-4 py-3">文章数</th>
                    <th class="px-4 py-3">排序</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($categories as $category)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $category->name }}</td>
                        <td class="px-4 py-3">{{ $category->slug }}</td>
                        <td class="px-4 py-3">{{ $category->articles_count }}</td>
                        <td class="px-4 py-3">{{ $category->sort_order }}</td>
                        <td class="px-4 py-3">{{ $category->active ? '启用' : '停用' }}</td>
                        <td class="space-x-3 px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.kb.categories.edit', $category) }}">编辑</a>
                            <form class="inline" method="post" action="{{ route('admin.kb.categories.destroy', $category) }}" onsubmit="return confirm('确定删除该分类？')">
                                @csrf
                                @method('delete')
                                <button class="text-red-600">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无分类</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $categories->links() }}</div>
@endsection
