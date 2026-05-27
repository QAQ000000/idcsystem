@extends('layouts.admin')

@section('title', '翻译管理')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">翻译管理</h1>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('admin.translations.import') }}">
                @csrf
                <button class="rounded border px-4 py-2 text-sm">导入语言文件</button>
            </form>
            <form method="POST" action="{{ route('admin.translations.export') }}">
                @csrf
                <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">导出语言文件</button>
            </form>
        </div>
    </div>

    <form class="mb-4 grid gap-3 rounded bg-white p-4 shadow-sm md:grid-cols-4" method="GET">
        <select class="rounded border px-3 py-2" name="locale">
            @foreach ($locales as $locale)
                <option value="{{ $locale }}" @selected($currentLocale === $locale)>{{ $locale }}</option>
            @endforeach
        </select>
        <select class="rounded border px-3 py-2" name="group">
            <option value="">全部分组</option>
            @foreach ($groups as $group)
                <option value="{{ $group }}" @selected($currentGroup === $group)>{{ $group }}</option>
            @endforeach
        </select>
        <input class="rounded border px-3 py-2" name="search" value="{{ $search }}" placeholder="搜索 key 或译文">
        <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
    </form>

    <form class="mb-4 flex gap-3 rounded bg-white p-4 shadow-sm" method="POST" action="{{ route('admin.translations.auto-translate') }}">
        @csrf
        <select class="rounded border px-3 py-2" name="from">
            @foreach ($locales as $locale)
                <option value="{{ $locale }}" @selected($locale === 'zh_CN')>{{ $locale }}</option>
            @endforeach
        </select>
        <select class="rounded border px-3 py-2" name="to">
            @foreach ($locales as $locale)
                <option value="{{ $locale }}" @selected($locale === 'en')>{{ $locale }}</option>
            @endforeach
        </select>
        <button class="rounded border px-4 py-2">自动翻译缺失项</button>
    </form>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">语言</th>
                    <th class="px-4 py-3">分组</th>
                    <th class="px-4 py-3">Key</th>
                    <th class="px-4 py-3">译文</th>
                    <th class="px-4 py-3">来源</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($translations as $translation)
                    <tr>
                        <td class="px-4 py-3">{{ $translation->locale }}</td>
                        <td class="px-4 py-3">{{ $translation->group }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $translation->key }}</td>
                        <td class="px-4 py-3">
                            <form class="flex gap-2" method="POST" action="{{ route('admin.translations.update', $translation) }}">
                                @csrf
                                @method('PUT')
                                <input class="w-full rounded border px-3 py-2" name="value" value="{{ $translation->value }}">
                                <button class="rounded border px-3 py-2">保存</button>
                            </form>
                        </td>
                        <td class="px-4 py-3">{{ $translation->translated_by ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $translation->is_translated ? '已翻译' : '待翻译' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无翻译</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $translations->links() }}</div>
@endsection
