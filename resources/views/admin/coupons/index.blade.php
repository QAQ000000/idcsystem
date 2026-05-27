@extends('layouts.admin')

@section('title', '优惠券管理')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">优惠券管理</h1>
            <p class="mt-1 text-sm text-slate-500">创建和管理客户可领取的优惠券。</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.coupons.create') }}">新建优惠券</a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <section class="rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">类型 / 面值</th>
                    <th class="px-4 py-3">最低订单金额</th>
                    <th class="px-4 py-3">库存 / 已领</th>
                    <th class="px-4 py-3">有效期</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($coupons as $coupon)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $coupon->name }}</div>
                            @if ($coupon->description)
                                <div class="text-slate-500">{{ $coupon->description }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($coupon->type === 'percent')
                                折扣 {{ $coupon->value }}%
                            @else
                                减免 ¥{{ $coupon->value }}
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $coupon->min_order_amount > 0 ? '¥'.$coupon->min_order_amount : '无限制' }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $coupon->stock == 0 ? '不限' : $coupon->stock }} / {{ $coupon->claims_count }}
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <div>{{ $coupon->starts_at ? $coupon->starts_at->format('Y-m-d') : '立即' }}</div>
                            <div>{{ $coupon->expires_at ? $coupon->expires_at->format('Y-m-d') : '永久' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($coupon->is_active)
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">启用</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">禁用</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.coupons.edit', $coupon) }}">编辑</a>
                            <form method="post" action="{{ route('admin.coupons.destroy', $coupon) }}" class="ml-3 inline"
                                  onsubmit="return confirm('确认删除该优惠券？')">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-700">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无优惠券</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-6">{{ $coupons->links() }}</div>
@endsection
