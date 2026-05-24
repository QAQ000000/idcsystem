@extends('layouts.admin')

@section('title', '通知中心')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">通知中心</h1>

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        @foreach ([
            ['邮件日志', $emailLogCount, route('admin.email-logs.index')],
            ['短信日志', $smsLogCount, route('admin.sms-logs.index')],
            ['邮件模板', $emailTemplateCount, route('admin.email-templates.index')],
            ['短信模板', $smsTemplateCount, route('admin.sms-templates.index')],
        ] as [$label, $count, $url])
            <a class="rounded bg-white p-5 shadow-sm" href="{{ $url }}">
                <div class="text-sm text-slate-500">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold">{{ $count }}</div>
            </a>
        @endforeach
    </div>

    <section class="rounded bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="font-semibold">通知策略</h2>
            <a class="text-sm text-blue-600" href="{{ route('admin.settings.index') }}">修改设置</a>
        </div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">事件</th>
                    <th class="px-4 py-3">邮件</th>
                    <th class="px-4 py-3">短信</th>
                    <th class="px-4 py-3">变量</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($events as $key => $event)
                    <tr>
                        <td class="px-4 py-3">{{ $event['label'] }}</td>
                        <td class="px-4 py-3">{{ $settings->get('notify_' . $key . '_mail', true) ? '启用' : '关闭' }}</td>
                        <td class="px-4 py-3">{{ $settings->get('notify_' . $key . '_sms', true) ? '启用' : '关闭' }}</td>
                        <td class="px-4 py-3">{{ implode(', ', $event['variables']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection
