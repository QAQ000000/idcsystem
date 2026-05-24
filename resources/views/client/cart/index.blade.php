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
                        <td class="px-4 py-3">{{ $item['product_name'] }}</td>
                        <td class="px-4 py-3">{{ $item['billing_cycle'] }}</td>
                        <td class="px-4 py-3">{{ $item['qty'] }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $item['price'], 2) }}</td>
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

    <form method="post" action="{{ route('client.cart.checkout') }}" class="mt-4">
        @csrf
        <button class="rounded bg-zinc-900 px-4 py-2 text-white" @disabled(count($cart['items'] ?? []) === 0)>结算并生成账单</button>
    </form>
@endsection
