@csrf
@if ($taxRule->exists)
    @method('PUT')
@endif

<div class="grid gap-5 rounded bg-white p-6 shadow-sm md:grid-cols-2">
    <label class="block text-sm">
        规则名称
        <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name', $taxRule->name) }}" required>
    </label>

    <label class="block text-sm">
        税率百分比
        <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="rate" min="0" max="100" step="0.01" value="{{ old('rate', $taxRule->rate) }}" required>
    </label>

    <label class="block text-sm">
        国家代码
        <input class="mt-1 w-full rounded border px-3 py-2 uppercase" name="country_code" maxlength="2" value="{{ old('country_code', $taxRule->country_code) }}" placeholder="CN" required>
        <span class="mt-1 block text-xs text-slate-500">ISO 3166-1 alpha-2，两位大写字母。</span>
    </label>

    <label class="block text-sm">
        州/省代码
        <input class="mt-1 w-full rounded border px-3 py-2 uppercase" name="state_code" maxlength="10" value="{{ old('state_code', $taxRule->state_code) }}" placeholder="GD">
        <span class="mt-1 block text-xs text-slate-500">留空表示该国家全部地区。</span>
    </label>

    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="active" value="1" @checked(old('active', $taxRule->active ?? true))>
        启用
    </label>
</div>

@if ($errors->any())
    <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-5 flex gap-3">
    <button class="rounded bg-slate-900 px-4 py-2 text-white">保存规则</button>
    <a class="rounded border px-4 py-2" href="{{ route('admin.tax-rules.index') }}">返回列表</a>
</div>
