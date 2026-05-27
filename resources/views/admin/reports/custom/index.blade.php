@extends('layouts.admin')

@section('title', '自定义报表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">自定义报表</h1>
            <p class="mt-1 text-sm text-slate-500">只读 SQL 报表，支持执行记录和 CSV 导出。</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.reports.custom.create') }}">新建报表</a>
    </div>

    <section class="rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">计划</th>
                    <th class="px-4 py-3">创建人</th>
                    <th class="px-4 py-3">执行次数</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($reports as $report)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $report->name }}</div>
                            <div class="text-slate-500">{{ $report->description ?: '-' }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $report->schedule ?: '手动' }}</td>
                        <td class="px-4 py-3">{{ $report->creator?->username ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $report->executions_count }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.reports.custom.show', $report) }}">执行</a>
                            <a class="ml-3 text-blue-600" href="{{ route('admin.reports.custom.export', $report) }}">导出</a>
                            <form method="post" action="{{ route('admin.reports.custom.destroy', $report) }}" class="ml-3 inline">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-700">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="5">暂无自定义报表</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-6">{{ $reports->links() }}</div>
@endsection
