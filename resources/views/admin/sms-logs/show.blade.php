@extends('layouts.admin')

@section('title', '短信日志详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">短信日志详情</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $smsLog->phone }} / {{ $smsLog->provider ?: '-' }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.sms-logs.index') }}">返回列表</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="mb-4 font-semibold">{{ $smsLog->template ?: '普通短信' }}</h2>
            <div class="rounded border bg-slate-50 p-4 text-sm leading-7">
                {{ $smsLog->maskedContent() }}
            </div>
        </section>

        <aside class="rounded bg-white p-6 text-sm shadow-sm">
            <dl class="space-y-3">
                <div>
                    <dt class="text-slate-500">状态</dt>
                    <dd>{{ ['pending' => '待发送', 'processing' => '发送中', 'sent' => '已发送', 'failed' => '发送失败'][$smsLog->status] ?? $smsLog->status }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">模板编码</dt>
                    <dd>{{ $smsLog->template_code ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">发送次数</dt>
                    <dd>{{ $smsLog->attempts }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">发送时间</dt>
                    <dd>{{ $smsLog->sent_at?->format('Y-m-d H:i:s') ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">错误信息</dt>
                    <dd class="break-words text-red-700">{{ $smsLog->error ?: '-' }}</dd>
                </div>
            </dl>

            @if (in_array($smsLog->status, ['failed', 'pending'], true))
                <form method="post" action="{{ route('admin.sms-logs.retry', $smsLog) }}" class="mt-6">
                    @csrf
                    <button class="w-full rounded bg-slate-900 px-4 py-2 text-white">重新发送</button>
                </form>
            @endif
        </aside>
    </div>
@endsection
