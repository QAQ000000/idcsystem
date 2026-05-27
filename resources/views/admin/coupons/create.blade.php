@extends('layouts.admin')

@section('title', '新建优惠券')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">新建优惠券</h1>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded bg-red-50 px-4 py-3 text-sm text-red-700">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded bg-white p-6 shadow-sm">
        <form method="post" action="{{ route('admin.coupons.store') }}" x-data="couponForm()">
            @csrf

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">名称 <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                           required maxlength="120">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">描述</label>
                    <textarea name="description" rows="2" maxlength="500"
                              class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">{{ old('description') }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">优惠类型 <span class="text-red-500">*</span></label>
                    <select name="type" x-model="type"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                        <option value="fixed" {{ old('type', 'fixed') === 'fixed' ? 'selected' : '' }}>固定金额减免</option>
                        <option value="percent" {{ old('type') === 'percent' ? 'selected' : '' }}>百分比折扣</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">
                        面值 <span class="text-red-500">*</span>
                        <span x-show="type === 'percent'" class="text-slate-400">（1–100）</span>
                        <span x-show="type === 'fixed'" class="text-slate-400">（¥）</span>
                    </label>
                    <input type="number" name="value" value="{{ old('value') }}"
                           :max="type === 'percent' ? 100 : ''"
                           min="0.01" step="0.01"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                           required>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">最低订单金额（¥，0 表示不限）</label>
                    <input type="number" name="min_order_amount" value="{{ old('min_order_amount', 0) }}"
                           min="0" step="0.01"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">库存（0 表示不限）</label>
                    <input type="number" name="stock" value="{{ old('stock', 0) }}"
                           min="0" step="1"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">适用产品 ID（逗号分隔，留空表示全部）</label>
                    <input type="text" name="product_ids" value="{{ old('product_ids') }}"
                           placeholder="例：1,2,5"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">生效时间（留空立即生效）</label>
                    <input type="date" name="starts_at" value="{{ old('starts_at') }}"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">到期时间（留空永不过期）</label>
                    <input type="date" name="expires_at" value="{{ old('expires_at') }}"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>

                <div class="md:col-span-2">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', '1') ? 'checked' : '' }}
                               class="rounded border-slate-300">
                        立即启用
                    </label>
                </div>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="submit"
                        class="rounded bg-slate-900 px-5 py-2 text-sm text-white hover:bg-slate-700">
                    创建
                </button>
                <a href="{{ route('admin.coupons.index') }}"
                   class="rounded border border-slate-300 px-5 py-2 text-sm text-slate-600 hover:bg-slate-50">
                    取消
                </a>
            </div>
        </form>
    </div>

    <script>
        function couponForm() {
            return { type: '{{ old('type', 'fixed') }}' };
        }
    </script>
@endsection
