@extends('theme::layouts.app')

@section('title', '知识库搜索')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">知识库搜索</h1>
        <p class="mt-1 text-sm text-zinc-500">搜索关键词：{{ $query ?: '未输入' }}</p>
    </div>

    <form method="get" action="{{ route('client.kb.search') }}" class="mb-6 flex gap-2 rounded bg-white p-4 shadow-sm">
        <input class="flex-1 rounded border px-3 py-2" name="q" value="{{ $query }}" placeholder="搜索问题或关键词">
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">搜索</button>
    </form>

    <section class="rounded bg-white shadow-sm">
        <div class="divide-y divide-zinc-100">
            @forelse ($articles as $article)
                <a class="block p-5 hover:bg-zinc-50" href="{{ route('client.kb.article', [$article->category, $article]) }}">
                    <div class="text-sm text-zinc-500">{{ $article->category?->name }}</div>
                    <h2 class="mt-1 font-medium">{{ $article->title }}</h2>
                </a>
            @empty
                <div class="p-8 text-center text-zinc-500">没有找到相关文章</div>
            @endforelse
        </div>
    </section>
@endsection
