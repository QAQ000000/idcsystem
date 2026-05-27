@extends('layouts.admin')

@section('title', $customReport->name)

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $customReport->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $customReport->description ?: '自定义报表' }}</p>
        </div>
        <div class="flex gap-3">
            <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.reports.custom.index') }}">返回</a>
            <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.reports.custom.export', $customReport) }}">导出 CSV</a>
        </div>
    </div>

    @if ($error)
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</div>
    @endif

    <section class="mb-6 rounded bg-white p-5 shadow-sm">
        <div class="mb-3 text-sm text-slate-500">SQL</div>
        <pre class="overflow-auto rounded bg-slate-950 p-4 text-xs text-white">{{ $customReport->query }}</pre>
    </section>

    <section class="mb-6 rounded bg-white shadow-sm">
        <div class="border-b px-5 py-4 font-semibold">执行结果</div>
        <div class="overflow-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        @foreach (($result['columns'] ?? []) as $column)
                            <th class="px-4 py-3">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse (($result['rows'] ?? []) as $row)
                        <tr>
                            @foreach (($result['columns'] ?? []) as $column)
                                <td class="px-4 py-3">{{ $row[$column] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-slate-500" colspan="{{ max(1, count($result['columns'] ?? [])) }}">暂无数据</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded bg-white shadow-sm">
        <div class="border-b px-5 py-4 font-semibold">最近执行</div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">行数</th>
                    <th class="px-4 py-3">耗时</th>
                    <th class="px-4 py-3">错误</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($customReport->executions as $execution)
                    <tr>
                        <td class="px-4 py-3">{{ $execution->executed_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $execution->status }}</td>
                        <td class="px-4 py-3">{{ $execution->rows_count }}</td>
                        <td class="px-4 py-3">{{ $execution->execution_time }}ms</td>
                        <td class="px-4 py-3">{{ $execution->error ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection
