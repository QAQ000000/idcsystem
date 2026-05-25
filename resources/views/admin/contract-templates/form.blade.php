<form method="post" action="{{ $action }}" class="rounded bg-white p-6 shadow-sm">
    @csrf
    @if ($method !== 'post')
        @method($method)
    @endif

    <div class="space-y-5">
        <label class="block text-sm">
            <span class="font-medium text-slate-700">模板名称</span>
            <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name', $template->name) }}" required>
            @error('name')
                <span class="mt-1 block text-red-600">{{ $message }}</span>
            @enderror
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked(old('active', $template->active))>
            <span>启用模板</span>
        </label>

        <label class="block text-sm">
            <span class="font-medium text-slate-700">合同正文</span>
            <textarea class="mt-1 min-h-96 w-full rounded border px-3 py-2 font-mono text-sm" name="content" required>{{ old('content', $template->content) }}</textarea>
            @error('content')
                <span class="mt-1 block text-red-600">{{ $message }}</span>
            @enderror
        </label>

        <div class="rounded border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            可用变量：{client_name}、{client_email}、{company_name}、{order_id}、{order_number}、{order_amount}、{date}
        </div>

        <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">保存模板</button>
    </div>
</form>
