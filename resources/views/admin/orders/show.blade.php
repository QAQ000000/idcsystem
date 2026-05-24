@extends('layouts.admin')

@section('title', '订单详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $order->order_number }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p>客户：{{ $order->client?->username }}</p>
            <p>状态：{{ $order->status }}</p>
            <p>金额：{{ $order->amount }}</p>
            <p>服务数量：{{ $order->hosts->count() }}</p>

            <div class="mt-5 divide-y">
                @foreach ($order->hosts as $host)
                    <div class="py-3 text-sm">
                        <div class="font-medium">{{ $host->product?->name }}</div>
                        <div class="text-slate-500">{{ $host->status }} / {{ $host->billing_cycle }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">订单操作</h2>
            <form method="post" action="{{ route('admin.orders.approve', $order) }}" class="space-y-3">
                @csrf
                <label class="block text-sm">
                    支付方式
                    <input class="mt-1 w-full rounded border px-3 py-2" name="payment_method" value="manual">
                </label>
                <label class="block text-sm">
                    交易号
                    <input class="mt-1 w-full rounded border px-3 py-2" name="trans_id" value="ADMIN-{{ $order->id }}">
                </label>
                <button class="w-full rounded bg-slate-900 px-4 py-2 text-white">审核并标记支付</button>
            </form>

            <form method="post" action="{{ route('admin.orders.cancel', $order) }}" class="mt-5 space-y-3">
                @csrf
                <label class="block text-sm">
                    取消原因
                    <input class="mt-1 w-full rounded border px-3 py-2" name="reason" value="后台取消">
                </label>
                <button class="w-full rounded border border-red-300 px-4 py-2 text-red-700">取消订单</button>
            </form>
        </div>
    </div>
@endsection
