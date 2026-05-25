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

        @php
            $sensitivePattern = '/(password|passwd|secret|token|credential|authorization|cookie|session_id|session|bearer|access_key|private_key|key|signature|sign)$/i';
            $config = $plugin->config ?? [];
            $oldConfigValue = function (string $key, mixed $default = '') {
                $value = old('config.' . $key, $default);

                return is_scalar($value) || $value === null ? $value : $default;
            };
        @endphp
        @foreach ($configFields as $field)
            @php($key = $field['key'])
            @php($label = $field['label'])
            @php($type = $field['type'] ?? 'text')
            @php($isSensitive = $type === 'password' || preg_match($sensitivePattern, $key) === 1)
            @php($savedValue = $config[$key] ?? '')
            @php($savedValue = is_scalar($savedValue) ? $savedValue : '')
            <label class="mb-4 block text-sm">
                {{ $label }}
                @if ($type === 'textarea')
                    <textarea class="mt-1 w-full rounded border px-3 py-2" name="config[{{ $key }}]" rows="4">{{ $oldConfigValue($key, $isSensitive ? '' : $savedValue) }}</textarea>
                @elseif ($type === 'boolean')
                    <select class="mt-1 w-full rounded border px-3 py-2" name="config[{{ $key }}]">
                        <option value="0" @selected((string) $oldConfigValue($key, (string) (int) (bool) $savedValue) === '0')>否</option>
                        <option value="1" @selected((string) $oldConfigValue($key, (string) (int) (bool) $savedValue) === '1')>是</option>
                    </select>
                @else
                    <input
                        class="mt-1 w-full rounded border px-3 py-2"
                        name="config[{{ $key }}]"
                        type="{{ $isSensitive || $type === 'password' ? 'password' : ($type === 'number' ? 'number' : 'text') }}"
                        value="{{ $oldConfigValue($key, $isSensitive ? '' : $savedValue) }}"
                        placeholder="{{ $isSensitive && !empty($config[$key] ?? '') ? '已保存，留空则不修改' : ($field['placeholder'] ?? '') }}"
                        @if ($type === 'number' && isset($field['min'])) min="{{ $field['min'] }}" @endif
                        @if ($type === 'number' && isset($field['max'])) max="{{ $field['max'] }}" @endif
                        autocomplete="off"
                    >
                @endif
            </label>
        @endforeach

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="rounded bg-slate-900 px-4 py-2 text-white">保存插件配置</button>
    </form>
@endsection
