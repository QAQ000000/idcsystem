@extends('layouts.admin')

@section('title', '短信日志')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">短信日志</h1>
        <form method="get" class="flex items-center gap-2 text-sm">
            <select class="rounded border px-3 py-2" name="status">
                @foreach (['' => '全部状态', 'pending' => '待发送', 'sent' => '已发送', 'failed' => '发送失败'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
        </form>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">手机号</th>
                    <th class="px-4 py-3">模板</th>
                    <th class="px-4 py-3">接口</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">次数</th>
                    <th class="px-4 py-3">创建时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3">{{ $log->phone }}</td>
                        <td class="px-4 py-3">{{ $log->template ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $log->provider ?: '-' }}</td>
                        <td class="px-4 py-3">{{ ['pending' => '待发送', 'sent' => '已发送', 'failed' => '发送失败'][$log->status] ?? $log->status }}</td>
                        <td class="px-4 py-3">{{ $log->attempts }}</td>
                        <td class="px-4 py-3">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.sms-logs.show', $log) }}">查看</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无短信日志</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
