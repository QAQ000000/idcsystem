@csrf
@if ($promoCode->exists)
    @method('PUT')
@endif

@php($selectedProducts = collect(old('product_ids', $promoCode->product_ids ?? []))->map(fn ($id) => (int) $id)->all())

<div class="grid gap-5 rounded bg-white p-6 shadow-sm md:grid-cols-2">
    <label class="block text-sm">
        优惠码
        <input class="mt-1 w-full rounded border px-3 py-2 uppercase" name="code" value="{{ old('code', $promoCode->code) }}" required>
    </label>

    <label class="block text-sm">
        类型
        <select class="mt-1 w-full rounded border px-3 py-2" name="type" required>
            <option value="percentage" @selected(old('type', $promoCode->type ?? 'percentage') === 'percentage')>百分比</option>
            <option value="fixed" @selected(old('type', $promoCode->type) === 'fixed')>固定金额</option>
        </select>
    </label>

    <label class="block text-sm">
        折扣值
        <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="value" min="0.01" step="0.01" value="{{ old('value', $promoCode->value) }}" required>
    </label>

    <label class="block text-sm">
        适用范围
        <select class="mt-1 w-full rounded border px-3 py-2" name="applies_to" required>
            <option value="all" @selected(old('applies_to', $promoCode->applies_to ?? 'all') === 'all')>全部产品</option>
            <option value="products" @selected(old('applies_to', $promoCode->applies_to) === 'products')>指定产品</option>
        </select>
    </label>

    <label class="block text-sm">
        最大使用次数
        <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="max_uses" min="0" value="{{ old('max_uses', $promoCode->max_uses ?? 0) }}">
        <span class="mt-1 block text-xs text-slate-500">填 0 表示不限次数。</span>
    </label>

    <label class="block text-sm">
        开始时间
        <input class="mt-1 w-full rounded border px-3 py-2" type="datetime-local" name="starts_at" value="{{ old('starts_at', $promoCode->starts_at?->format('Y-m-d\TH:i')) }}">
    </label>

    <label class="block text-sm">
        结束时间
        <input class="mt-1 w-full rounded border px-3 py-2" type="datetime-local" name="expires_at" value="{{ old('expires_at', $promoCode->expires_at?->format('Y-m-d\TH:i')) }}">
    </label>

    <div class="md:col-span-2">
        <div class="text-sm font-medium">指定产品</div>
        <div class="mt-2 grid max-h-56 gap-2 overflow-y-auto rounded border p-3 text-sm md:grid-cols-3">
            @forelse ($products as $product)
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array((int) $product->id, $selectedProducts, true))>
                    <span>{{ $product->name }}</span>
                </label>
            @empty
                <div class="text-slate-500">暂无产品</div>
            @endforelse
        </div>
        <p class="mt-1 text-xs text-slate-500">适用范围选择“指定产品”时生效。</p>
    </div>

    <div class="md:col-span-2 flex flex-wrap gap-5 text-sm">
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="once_per_client" value="1" @checked(old('once_per_client', $promoCode->once_per_client ?? false))>
            每个客户仅可使用一次
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="active" value="1" @checked(old('active', $promoCode->active ?? true))>
            启用
        </label>
    </div>
</div>

@if ($errors->any())
    <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-5 flex gap-3">
    <button class="rounded bg-slate-900 px-4 py-2 text-white">保存优惠码</button>
    <a class="rounded border px-4 py-2" href="{{ route('admin.promo-codes.index') }}">返回列表</a>
</div>
