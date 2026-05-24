@php($product = $product ?? null)

@csrf
@if ($product)
    @method('PUT')
@endif

<div class="grid gap-5 rounded bg-white p-6 shadow-sm md:grid-cols-2">
    <label class="block text-sm">
        产品名称
        <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name', $product?->name) }}" required>
    </label>

    <label class="block text-sm">
        产品分组
        <select class="mt-1 w-full rounded border px-3 py-2" name="group_id" required>
            @foreach ($groups as $group)
                <option value="{{ $group->id }}" @selected((int) old('group_id', $product?->group_id) === (int) $group->id)>{{ $group->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="block text-sm">
        产品类型
        <select class="mt-1 w-full rounded border px-3 py-2" name="type">
            @foreach (['hosting' => '虚拟主机', 'vps' => '云服务器', 'dedicated' => '独立服务器', 'other' => '其他'] as $value => $label)
                <option value="{{ $value }}" @selected(old('type', $product?->type ?? 'vps') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label class="block text-sm">
        开通方式
        <select class="mt-1 w-full rounded border px-3 py-2" name="auto_setup">
            @foreach (['manual' => '人工审核', 'payment' => '付款后自动开通', 'order' => '下单后自动开通'] as $value => $label)
                <option value="{{ $value }}" @selected(old('auto_setup', $product?->auto_setup ?? 'manual') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label class="block text-sm">
        服务器模块
        <select class="mt-1 w-full rounded border px-3 py-2" name="server_type">
            <option value="">不绑定服务器模块</option>
            @foreach (($serverPlugins ?? collect()) as $plugin)
                <option value="{{ $plugin->name }}" @selected(old('server_type', $product?->server_type) === $plugin->name)>
                    {{ $plugin->title ?: $plugin->name }}
                </option>
            @endforeach
        </select>
    </label>

    <label class="block text-sm">
        库存数量
        <input class="mt-1 w-full rounded border px-3 py-2" name="stock_qty" type="number" min="0" value="{{ old('stock_qty', $product?->stock_qty ?? 0) }}">
    </label>

    <label class="block text-sm">
        排序
        <input class="mt-1 w-full rounded border px-3 py-2" name="sort_order" type="number" value="{{ old('sort_order', $product?->sort_order ?? 0) }}">
    </label>

    <label class="md:col-span-2 block text-sm">
        产品说明
        <textarea class="mt-1 w-full rounded border px-3 py-2" name="description" rows="5">{{ old('description', $product?->description) }}</textarea>
    </label>

    <div class="md:col-span-2 flex flex-wrap gap-5 text-sm">
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="stock_control" value="1" @checked(old('stock_control', $product?->stock_control ?? true))>
            启用库存控制
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product?->is_featured ?? false))>
            推荐产品
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="hidden" value="1" @checked(old('hidden', $product?->hidden ?? false))>
            隐藏
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="retired" value="1" @checked(old('retired', $product?->retired ?? false))>
            停售
        </label>
    </div>
</div>

@if ($errors->any())
    <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-5 flex gap-3">
    <button class="rounded bg-slate-900 px-4 py-2 text-white">保存产品</button>
    <a class="rounded border px-4 py-2" href="{{ route('admin.products.index') }}">返回列表</a>
</div>
