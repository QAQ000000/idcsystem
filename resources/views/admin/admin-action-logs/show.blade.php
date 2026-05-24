@extends('layouts.admin')

@section('title', '审计详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">审计详情</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.admin-action-logs.index') }}">返回列表</a>
    </div>

    <div class="rounded bg-white p-6 shadow-sm">
        <dl class="grid gap-4 text-sm md:grid-cols-2">
            <div>
                <dt class="text-slate-500">动作</dt>
                <dd class="mt-1 font-medium">{{ $log->action }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">结果</dt>
                <dd class="mt-1 font-medium">{{ $log->result }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">管理员</dt>
                <dd class="mt-1">{{ $log->admin?->username ?: 'system' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">目标</dt>
                <dd class="mt-1">{{ $log->target_type ? $log->target_type . '#' . $log->target_id : '-' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">IP</dt>
                <dd class="mt-1">{{ $log->ip_address ?: '-' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">时间</dt>
                <dd class="mt-1">{{ $log->created_at?->format('Y-m-d H:i:s') }}</dd>
            </div>
        </dl>

        <div class="mt-6">
            <h2 class="mb-2 font-semibold">Payload</h2>
            <pre class="overflow-auto rounded bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($log->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>

        @if ($log->error)
            <div class="mt-6">
                <h2 class="mb-2 font-semibold">错误</h2>
                <pre class="overflow-auto rounded bg-red-50 p-4 text-xs text-red-800">{{ $log->error }}</pre>
            </div>
        @endif

        <div class="mt-6">
            <h2 class="mb-2 font-semibold">User Agent</h2>
            <div class="rounded bg-slate-50 p-4 text-sm">{{ $log->user_agent ?: '-' }}</div>
        </div>
    </div>
@endsection
