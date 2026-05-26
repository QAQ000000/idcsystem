@csrf

<section class="rounded bg-white p-6 shadow-sm">
    <div class="grid gap-4 md:grid-cols-2">
        <label class="block text-sm">
            名称
            <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name', $webhook->name) }}" required>
        </label>
        <label class="block text-sm">
            URL
            <input class="mt-1 w-full rounded border px-3 py-2" name="url" value="{{ old('url', $webhook->url) }}" required>
        </label>
        <label class="block text-sm md:col-span-2">
            Secret
            <input class="mt-1 w-full rounded border px-3 py-2 font-mono text-sm" name="secret" value="{{ old('secret', $webhook->secret) }}" placeholder="留空自动生成">
        </label>
        <fieldset class="md:col-span-2">
            <legend class="mb-2 text-sm font-medium">订阅事件</legend>
            <div class="grid gap-2 md:grid-cols-3">
                @foreach ($events as $key => $label)
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="events[]" value="{{ $key }}" @checked(in_array($key, old('events', $webhook->events ?? []), true))>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="active" value="1" @checked(old('active', $webhook->active ?? true))>
            启用
        </label>
    </div>

    @if ($errors->any())
        <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <button class="mt-6 rounded bg-slate-900 px-4 py-2 text-white">保存 Webhook</button>
</section>
