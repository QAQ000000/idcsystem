@extends('layouts.admin')

@section('title', '日志中心')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">日志中心</h1>
            <p class="mt-1 text-sm text-slate-500">统一查看日志规模、时间范围和保留策略。</p>
        </div>
        @can('log.manage')
            <form method="post" action="{{ route('admin.logs.cleanup') }}">
                @csrf
                <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">立即清理</button>
            </form>
        @endcan
    </div>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">日志表</th>
                    <th class="px-4 py-3">记录数</th>
                    <th class="px-4 py-3">最早时间</th>
                    <th class="px-4 py-3">最新时间</th>
                    <th class="px-4 py-3">保留天数</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($summaries as $summary)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $summary['table'] }}</td>
                        <td class="px-4 py-3">{{ $summary['count'] }}</td>
                        <td class="px-4 py-3">{{ $summary['oldest'] ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $summary['latest'] ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $summary['retention_days'] }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.logs.show', $summary['table']) }}">查看</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection
