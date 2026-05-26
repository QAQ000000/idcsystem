@extends('theme::layouts.app')

@section('title', $category->name)

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $category->name }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $category->description }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.kb.index') }}">返回知识库</a>
    </div>

    <section class="rounded bg-white shadow-sm">
        <div class="divide-y divide-zinc-100">
            @forelse ($articles as $article)
                <a class="block p-5 hover:bg-zinc-50" href="{{ route('client.kb.article', [$category, $article]) }}">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="font-medium">{{ $article->title }}</h2>
                        <span class="text-sm text-zinc-500">{{ $article->views }} 次浏览</span>
                    </div>
                </a>
            @empty
                <div class="p-8 text-center text-zinc-500">暂无文章</div>
            @endforelse
        </div>
    </section>

    <div class="mt-4">{{ $articles->links() }}</div>
@endsection
