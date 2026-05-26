@csrf
@if ($clientGroup->exists)
    @method('PUT')
@endif

<div class="grid gap-5 rounded bg-white p-6 shadow-sm md:grid-cols-2">
    <label class="block text-sm">
        分组名称
        <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name', $clientGroup->name) }}" required>
    </label>

    <label class="block text-sm">
        折扣百分比
        <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="discount_percent" min="0" max="100" step="0.01" value="{{ old('discount_percent', $clientGroup->discount_percent) }}" required>
        <span class="mt-1 block text-xs text-slate-500">结算时先应用优惠码，再按剩余金额应用客户分组折扣。</span>
    </label>

    <label class="block text-sm">
        标识颜色
        <input class="mt-1 h-10 w-full rounded border px-3 py-2" name="color" value="{{ old('color', $clientGroup->color) }}" placeholder="#64748b">
    </label>
</div>

@if ($errors->any())
    <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-5 flex gap-3">
    <button class="rounded bg-slate-900 px-4 py-2 text-white">保存分组</button>
    <a class="rounded border px-4 py-2" href="{{ route('admin.client-groups.index') }}">返回列表</a>
</div>
