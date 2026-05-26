@extends('theme::layouts.app')

@section('title', '通知偏好')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">通知偏好</h1>
        <p class="mt-1 text-sm text-zinc-500">选择需要接收的业务通知，账户安全通知会始终发送。</p>
    </div>

    <form method="post" action="{{ route('client.account.notifications.update') }}" class="rounded bg-white p-6 shadow-sm">
        @csrf

        <div class="divide-y divide-zinc-100">
            @foreach ($events as $key => $event)
                @php($isMandatory = in_array($key, $mandatory, true))
                @php($enabled = $isMandatory || (bool) ($preferences[$key] ?? true))
                <div class="flex items-center justify-between gap-4 py-4">
                    <div>
                        <div class="font-medium">{{ $event['label'] }}</div>
                        <div class="mt-1 text-sm text-zinc-500">
                            {{ $isMandatory ? '账户安全通知，不能关闭。' : '关闭后将不再发送该类邮件或短信。' }}
                        </div>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="hidden" name="notifications[{{ $key }}]" value="0">
                        <input
                            class="h-4 w-4 rounded border-zinc-300"
                            type="checkbox"
                            name="notifications[{{ $key }}]"
                            value="1"
                            @checked($enabled)
                            @disabled($isMandatory)
                        >
                        <span>{{ $enabled ? '开启' : '关闭' }}</span>
                    </label>
                    @if ($isMandatory)
                        <input type="hidden" name="notifications[{{ $key }}]" value="1">
                    @endif
                </div>
            @endforeach
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="mt-6 rounded bg-zinc-900 px-4 py-2 text-white">保存通知偏好</button>
    </form>
@endsection
