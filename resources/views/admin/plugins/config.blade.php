@extends('layouts.admin')

@section('title', '插件配置')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $plugin->title ?: $plugin->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $plugin->type }} / {{ $plugin->version }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.plugins.index') }}">返回插件列表</a>
    </div>

    <form method="post" action="{{ route('admin.plugins.config.save', $plugin->name) }}" class="rounded bg-white p-6 shadow-sm">
        @csrf

        @php($config = $plugin->config ?? [])
        @foreach (['app_id' => '应用 ID', 'app_secret' => '应用密钥', 'endpoint' => '接口地址', 'callback_url' => '回调地址'] as $key => $label)
            <label class="mb-4 block text-sm">
                {{ $label }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="config[{{ $key }}]" value="{{ old('config.' . $key, $config[$key] ?? '') }}">
            </label>
        @endforeach

        <label class="mb-4 block text-sm">
            备注
            <textarea class="mt-1 w-full rounded border px-3 py-2" name="config[notes]" rows="4">{{ old('config.notes', $config['notes'] ?? '') }}</textarea>
        </label>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="rounded bg-slate-900 px-4 py-2 text-white">保存插件配置</button>
    </form>
@endsection
