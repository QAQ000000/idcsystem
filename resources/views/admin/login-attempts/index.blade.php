@extends('layouts.admin')

@section('title', '登录记录')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">登录记录</h1>
            <p class="mt-1 text-sm text-slate-500">查看客户登录成功、失败和锁定相关审计记录。</p>
        </div>
    </div>

    <section class="mb-6 rounded bg-white p-6 shadow-sm">
        <form method="get" action="{{ route('admin.login-attempts.index') }}" class="grid gap-4 md:grid-cols-4">
            <label class="block text-sm">
                <span class="font-medium text-slate-700">邮箱</span>
                <input class="mt-1 w-full rounded border px-3 py-2" type="text" name="email" value="{{ $filters['email'] ?? '' }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-slate-700">IP</span>
                <input class="mt-1 w-full rounded border px-3 py-2" type="text" name="ip" value="{{ $filters['ip'] ?? '' }}">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-slate-700">状态</span>
                <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                    <option value="">全部</option>
                    <option value="success" @selected(($filters['status'] ?? '') === 'success')>成功</option>
                    <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>失败</option>
                </select>
            </label>
            <div class="flex items-end gap-2">
                <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">筛选</button>
                <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.login-attempts.index') }}">重置</a>
            </div>
        </form>
    </section>

    <section class="rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">邮箱</th>
                    <th class="px-4 py-3">IP</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">失败原因</th>
                    <th class="px-4 py-3">设备</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($attempts as $attempt)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $attempt->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $attempt->email }}</td>
                        <td class="px-4 py-3">{{ $attempt->ip }}</td>
                        <td class="px-4 py-3">
                            @if ($attempt->status === 'success')
                                <span class="rounded bg-emerald-50 px-2 py-1 text-xs text-emerald-700">成功</span>
                            @else
                                <span class="rounded bg-red-50 px-2 py-1 text-xs text-red-700">失败</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $attempt->failure_reason ?: '-' }}</td>
                        <td class="px-4 py-3 max-w-md truncate">{{ $attempt->user_agent ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无登录记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="border-t px-4 py-3">
            {{ $attempts->links() }}
        </div>
    </section>
@endsection
