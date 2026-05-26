@extends('layouts.admin')

@section('title', $type)

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $type }}</h1>
            <p class="mt-1 text-sm text-slate-500">支持关键字、状态和日期范围过滤。</p>
        </div>
        <a class="text-sm text-blue-600" href="{{ route('admin.logs.index') }}">返回日志中心</a>
    </div>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-5">
        <input class="rounded border px-3 py-2" name="q" value="{{ $filters['search'] }}" placeholder="关键字">
        <input class="rounded border px-3 py-2" name="status" value="{{ $filters['status'] }}" placeholder="状态">
        <input class="rounded border px-3 py-2" type="date" name="from" value="{{ $filters['from'] }}">
        <input class="rounded border px-3 py-2" type="date" name="to" value="{{ $filters['to'] }}">
        <div class="flex gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.logs.show', $type) }}">重置</a>
        </div>
    </form>

    <section class="overflow-x-auto rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    @foreach ($columns as $column)
                        <th class="px-4 py-3">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td class="max-w-xs truncate px-4 py-3">{{ data_get($row, $column) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="{{ count($columns) }}">暂无日志</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
