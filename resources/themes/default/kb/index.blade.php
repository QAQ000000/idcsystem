@extends('theme::layouts.app')

@section('title', '知识库')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">知识库</h1>
        <p class="mt-1 text-sm text-zinc-500">查找常见问题、服务使用指南和故障处理步骤。</p>
    </div>

    <form method="get" action="{{ route('client.kb.search') }}" class="mb-6 flex gap-2 rounded bg-white p-4 shadow-sm">
        <input class="flex-1 rounded border px-3 py-2" name="q" placeholder="搜索问题或关键词">
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">搜索</button>
    </form>

    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($categories as $category)
            <a class="rounded bg-white p-5 shadow-sm hover:shadow" href="{{ route('client.kb.category', $category) }}">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">{{ $category->name }}</h2>
                    <span class="text-sm text-zinc-500">{{ $category->articles_count }} 篇</span>
                </div>
                <p class="mt-2 text-sm text-zinc-600">{{ $category->description ?: '查看该分类下的帮助文章。' }}</p>
            </a>
        @empty
            <section class="rounded bg-white p-8 text-center text-zinc-500 shadow-sm md:col-span-2">暂无知识库分类</section>
        @endforelse
    </div>
@endsection
