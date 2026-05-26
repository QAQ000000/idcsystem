@extends('theme::layouts.app')

@section('title', $article->title)

@section('content')
    <div class="mb-6">
        <a class="text-sm text-blue-700" href="{{ route('client.kb.category', $category) }}">{{ $category->name }}</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $article->title }}</h1>
        <p class="mt-1 text-sm text-zinc-500">{{ $article->views }} 次浏览</p>
    </div>

    <article class="rounded bg-white p-6 leading-7 shadow-sm">
        {!! nl2br(e($article->content)) !!}
    </article>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="font-semibold">这篇文章有帮助吗？</h2>
        <div class="mt-4 flex gap-3">
            <form method="post" action="{{ route('client.kb.feedback', $article) }}">
                @csrf
                <input type="hidden" name="helpful" value="1">
                <button class="rounded bg-emerald-700 px-4 py-2 text-sm text-white">有帮助</button>
            </form>
            <form method="post" action="{{ route('client.kb.feedback', $article) }}">
                @csrf
                <input type="hidden" name="helpful" value="0">
                <button class="rounded border px-4 py-2 text-sm">没有帮助</button>
            </form>
        </div>
    </section>
@endsection
