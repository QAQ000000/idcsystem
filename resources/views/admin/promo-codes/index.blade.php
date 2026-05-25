@extends('layouts.admin')

@section('title', '优惠码管理')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">优惠码管理</h1>
            <p class="mt-1 text-sm text-slate-500">创建、筛选、启停和删除购物车可用的优惠码。</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.promo-codes.create') }}">新建优惠码</a>
    </div>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-4">
        <label class="block text-sm">
            优惠码
            <input class="mt-1 w-full rounded border px-3 py-2" name="code" value="{{ $filters['code'] ?? '' }}" placeholder="输入 code 搜索">
        </label>
        <label class="block text-sm">
            状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部状态</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>启用</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>停用</option>
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.promo-codes.index') }}">重置</a>
        </div>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">优惠码</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">折扣</th>
                    <th class="px-4 py-3">已用/上限</th>
                    <th class="px-4 py-3">有效期</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($promoCodes as $promoCode)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $promoCode->code }}</td>
                        <td class="px-4 py-3">{{ $promoCode->type === 'percentage' ? '百分比' : '固定金额' }}</td>
                        <td class="px-4 py-3">{{ $promoCode->type === 'percentage' ? rtrim(rtrim(number_format((float) $promoCode->value, 2, '.', ''), '0'), '.') . '%' : $promoCode->value }}</td>
                        <td class="px-4 py-3">{{ $promoCode->used_count }} / {{ $promoCode->max_uses > 0 ? $promoCode->max_uses : '不限' }}</td>
                        <td class="px-4 py-3">
                            {{ $promoCode->starts_at?->format('Y-m-d') ?: '立即' }}
                            -
                            {{ $promoCode->expires_at?->format('Y-m-d') ?: '长期' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded px-2 py-0.5 text-xs {{ $promoCode->active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                {{ $promoCode->active ? '启用' : '停用' }}
                            </span>
                        </td>
                        <td class="space-x-3 px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.promo-codes.edit', $promoCode) }}">编辑</a>
                            <form method="post" action="{{ route('admin.promo-codes.toggle', $promoCode) }}" class="inline">
                                @csrf
                                <button class="text-amber-700">{{ $promoCode->active ? '停用' : '启用' }}</button>
                            </form>
                            <form method="post" action="{{ route('admin.promo-codes.destroy', $promoCode) }}" class="inline" onsubmit="return confirm('确定删除该优惠码？已有使用记录的优惠码会改为停用。')">
                                @csrf
                                @method('delete')
                                <button class="text-red-600">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="7">暂无优惠码</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $promoCodes->links() }}</div>
@endsection
