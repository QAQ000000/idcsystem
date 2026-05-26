<label class="block text-sm">
    名称
    <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name', $addon->name) }}" required>
</label>
<label class="block text-sm">
    价格
    <input class="mt-1 w-full rounded border px-3 py-2" type="number" step="0.01" min="0.01" name="price" value="{{ old('price', $addon->price) }}" required>
</label>
<label class="block text-sm">
    计费
    <select class="mt-1 w-full rounded border px-3 py-2" name="billing_cycle">
        <option value="recurring" @selected(old('billing_cycle', $addon->billing_cycle) === 'recurring')>周期</option>
        <option value="one_time" @selected(old('billing_cycle', $addon->billing_cycle) === 'one_time')>一次性</option>
    </select>
</label>
<label class="block text-sm">
    库存
    <input class="mt-1 w-full rounded border px-3 py-2" type="number" min="0" name="stock_qty" value="{{ old('stock_qty', $addon->stock_qty) }}" placeholder="留空不限">
</label>
<label class="block text-sm">
    排序
    <input class="mt-1 w-full rounded border px-3 py-2" type="number" min="0" name="sort_order" value="{{ old('sort_order', $addon->sort_order ?? 0) }}">
</label>
<label class="mt-7 inline-flex items-center gap-2 text-sm">
    <input type="checkbox" name="active" value="1" @checked(old('active', $addon->active ?? true))>
    启用
</label>
<label class="block text-sm md:col-span-3">
    描述
    <textarea class="mt-1 w-full rounded border px-3 py-2" name="description" rows="2">{{ old('description', $addon->description) }}</textarea>
</label>
