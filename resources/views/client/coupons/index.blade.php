@extends('layouts.client')

@section('title', '优惠券')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">优惠券</h1>

    @if (session('status'))
        <div class="mb-4 rounded bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first('coupon') }}</div>
    @endif

    <section class="mb-8">
        <h2 class="mb-3 text-lg font-medium">可领取优惠券</h2>
        @if ($available->isEmpty())
            <p class="text-sm text-slate-500">暂无可领取的优惠券。</p>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($available as $coupon)
                    <div class="rounded border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-1 text-base font-semibold text-slate-800">{{ $coupon->name }}</div>
                        @if ($coupon->description)
                            <p class="mb-2 text-xs text-slate-500">{{ $coupon->description }}</p>
                        @endif
                        <div class="mb-3 text-2xl font-bold text-indigo-600">
                            @if ($coupon->type === 'percent')
                                {{ $coupon->value }}% 折扣
                            @else
                                ¥{{ $coupon->value }} 减免
                            @endif
                        </div>
                        <ul class="mb-4 space-y-0.5 text-xs text-slate-500">
                            @if ($coupon->min_order_amount > 0)
                                <li>满 ¥{{ $coupon->min_order_amount }} 可用</li>
                            @endif
                            @if ($coupon->product_ids)
                                <li>限指定产品使用</li>
                            @endif
                            @if ($coupon->expires_at)
                                <li>有效期至 {{ $coupon->expires_at->format('Y-m-d') }}</li>
                            @endif
                            @if ($coupon->stock > 0)
                                <li>剩余 {{ $coupon->stock - $coupon->claimed_count }} 张</li>
                            @endif
                        </ul>
                        <form method="post" action="{{ route('client.coupons.claim', $coupon) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full rounded bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-700">
                                立即领取
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section>
        <h2 class="mb-3 text-lg font-medium">我的优惠券</h2>
        @if ($myClaims->isEmpty())
            <p class="text-sm text-slate-500">您尚未领取任何优惠券。</p>
        @else
            <div class="rounded bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-3">优惠券名称</th>
                            <th class="px-4 py-3">面值</th>
                            <th class="px-4 py-3">有效期</th>
                            <th class="px-4 py-3">领取时间</th>
                            <th class="px-4 py-3">状态</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($myClaims as $claim)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $claim->coupon->name }}</td>
                                <td class="px-4 py-3">
                                    @if ($claim->coupon->type === 'percent')
                                        {{ $claim->coupon->value }}% 折扣
                                    @else
                                        ¥{{ $claim->coupon->value }} 减免
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ $claim->coupon->expires_at ? $claim->coupon->expires_at->format('Y-m-d') : '永久' }}
                                </td>
                                <td class="px-4 py-3 text-xs">{{ $claim->claimed_at->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">
                                    @if ($claim->used_at)
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            已使用
                                        </span>
                                    @elseif ($claim->coupon->expires_at && $claim->coupon->expires_at->isPast())
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-600">
                                            已过期
                                        </span>
                                    @else
                                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">
                                            可使用
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
