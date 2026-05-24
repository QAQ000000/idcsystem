@extends('layouts.admin')

@section('title', '编辑短信模板')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">编辑短信模板</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $smsTemplate->name }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.sms-templates.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.sms-templates.update', $smsTemplate) }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')

        <label class="mb-4 block text-sm">
            短信内容
            <textarea class="mt-1 w-full rounded border px-3 py-2" name="content" rows="6" required>{{ old('content', $smsTemplate->content) }}</textarea>
        </label>

        <label class="mb-4 inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $smsTemplate->enabled))>
            启用模板
        </label>

        <div class="mb-4 rounded border bg-slate-50 p-4 text-sm text-slate-600">
            可用变量：{{ implode(', ', \App\Services\NotificationService::events()[$smsTemplate->name]['variables'] ?? []) }}
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="rounded bg-slate-900 px-4 py-2 text-white">保存模板</button>
    </form>
@endsection
