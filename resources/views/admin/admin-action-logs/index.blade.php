@extends('layouts.admin')

@section('title', '后台审计')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">后台审计</h1>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-3">
        <label class="block text-sm">
            动作
            <select class="mt-1 w-full rounded border px-3 py-2" name="action">
                <option value="">全部动作</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            结果
            <select class="mt-1 w-full rounded border px-3 py-2" name="result">
                <option value="">全部结果</option>
                @foreach ($results as $result)
                    <option value="{{ $result }}" @selected(($filters['result'] ?? '') === $result)>{{ $result }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.admin-action-logs.index') }}">重置</a>
        </div>
    </form>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">管理员</th>
                    <th class="px-4 py-3">动作</th>
                    <th class="px-4 py-3">目标</th>
                    <th class="px-4 py-3">结果</th>
                    <th class="px-4 py-3">错误</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $log->admin?->username ?: 'system' }}</td>
                        <td class="px-4 py-3">{{ $log->action }}</td>
                        <td class="px-4 py-3">{{ $log->target_type ? class_basename($log->target_type) . '#' . $log->target_id : '-' }}</td>
                        <td class="px-4 py-3">{{ $log->result }}</td>
                        <td class="max-w-xs truncate px-4 py-3">{{ $log->error ?: '-' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.admin-action-logs.show', $log) }}">查看</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无审计日志</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
