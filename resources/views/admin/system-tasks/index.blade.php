@extends('layouts.admin')

@section('title', '系统任务')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">系统任务</h1>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-3">
        <label class="block text-sm">
            任务名称
            <select class="mt-1 w-full rounded border px-3 py-2" name="task_name">
                <option value="">全部任务</option>
                @foreach ($taskNames as $taskName)
                    <option value="{{ $taskName }}" @selected(($filters['task_name'] ?? '') === $taskName)>{{ $taskName }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部状态</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.system-tasks.index') }}">重置</a>
        </div>
    </form>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">任务</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">开始</th>
                    <th class="px-4 py-3">结束</th>
                    <th class="px-4 py-3">耗时</th>
                    <th class="px-4 py-3">输出</th>
                    <th class="px-4 py-3">错误</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3">{{ $log->task_name }}</td>
                        <td class="px-4 py-3">{{ $log->status }}</td>
                        <td class="px-4 py-3">{{ $log->started_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $log->finished_at?->format('Y-m-d H:i:s') ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $log->duration_ms !== null ? $log->duration_ms . ' ms' : '-' }}</td>
                        <td class="px-4 py-3 max-w-xs truncate">{{ $log->output ?: '-' }}</td>
                        <td class="px-4 py-3 max-w-xs truncate">{{ $log->error ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无任务日志</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
