@extends('layouts.client')

@section('title', '购物车')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">购物车</h1>
    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-600">
                <tr>
                    <th class="px-4 py-3">产品</th>
                    <th class="px-4 py-3">周期</th>
                    <th class="px-4 py-3">数量</th>
                    <th class="px-4 py-3">单价</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($cart['items'] ?? [] as $item)
                    <tr>
                        <td class="px-4 py-3">
                            <div>{{ $item['product_name'] }}</div>
                            @if (!empty($item['custom_field_labels']))
                                <dl class="mt-2 space-y-1 text-xs text-zinc-500">
                                    @foreach ($item['custom_field_labels'] as $label => $value)
                                        <div><dt class="inline">{{ $label }}：</dt><dd class="inline">{{ $value }}</dd></div>
                                    @endforeach
                                </dl>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $item['billing_cycle'] }}</td>
                        <td class="px-4 py-3">{{ $item['qty'] }}</td>
                        <td class="px-4 py-3">{{ $currencies->format((float) $item['price'], $currency) }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('client.cart.remove', $item['id']) }}">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-600">移除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-zinc-500" colspan="5">购物车为空</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[2fr_1fr]">
        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-semibold">优惠码</h2>
            @if ($errors->any())
                <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif

            @if (isset($cart['promo']))
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span>已应用：{{ $cart['promo']['code'] }}</span>
                    <form method="post" action="{{ route('client.cart.promo.remove') }}">
                        @csrf
                        @method('DELETE')
                        <button class="text-red-600">移除</button>
                    </form>
                </div>
            @else
                <form method="post" action="{{ route('client.cart.promo') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block text-sm">
                        代码
                        <input class="mt-1 rounded border px-3 py-2" name="code" value="{{ old('code') }}" maxlength="100" required>
                    </label>
                    <button class="rounded bg-zinc-900 px-4 py-2 text-white" @disabled(count($cart['items'] ?? []) === 0)>应用优惠码</button>
                </form>
            @endif
        </div>

        <div class="rounded bg-white p-5 text-sm shadow-sm">
            <div class="flex justify-between py-1">
                <span class="text-zinc-500">小计</span>
                <span>{{ $currencies->format((float) ($cart['totals']['subtotal'] ?? 0), $currency) }}</span>
            </div>
            <div class="flex justify-between py-1">
                <span class="text-zinc-500">优惠</span>
                <span>-{{ $currencies->format((float) ($cart['totals']['discount'] ?? 0), $currency) }}</span>
            </div>
            @if (($cart['totals']['group_discount'] ?? 0) > 0)
                <div class="flex justify-between py-1 text-emerald-700">
                    <span>客户分组折扣（{{ $cart['group_discount']['name'] ?? '默认分组' }}）</span>
                    <span>-{{ $currencies->format((float) ($cart['totals']['group_discount'] ?? 0), $currency) }}</span>
                </div>
            @endif
            <div class="mt-2 flex justify-between border-t pt-3 font-semibold">
                <span>合计</span>
                <span>{{ $currencies->format((float) ($cart['totals']['total'] ?? 0), $currency) }}</span>
            </div>
        </div>
    </div>

    <form method="post" action="{{ route('client.cart.checkout') }}" class="mt-4">
        @csrf
        <button class="rounded bg-zinc-900 px-4 py-2 text-white" @disabled(count($cart['items'] ?? []) === 0)>结算并生成账单</button>
    </form>
@endsection
