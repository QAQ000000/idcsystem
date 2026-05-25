@extends('layouts.admin')

@section('title', '订单详情')

@section('content')
    @php($adminUser = auth('admin')->user())
    @php($canAdmin = fn (string $permission): bool => $adminUser && ($adminUser->hasRole('super-admin') || $adminUser->can($permission)))
    @php($clientDeleted = $order->client?->trashed() ?? false)

    <h1 class="mb-4 text-2xl font-semibold">{{ $order->order_number }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p>
                客户：{{ $order->client?->username }}
                @if ($clientDeleted)
                    <span class="ml-1 inline-flex rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-700">已删除</span>
                @endif
            </p>
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
            @if ($clientDeleted)
                <p class="mb-4 text-sm text-slate-500">客户已删除，不能审核并标记支付该订单。</p>
            @endif

            @if (!$clientDeleted && $order->status === 'Pending' && $canAdmin('order.approve'))
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
            @endif

            @if ($order->status === 'Pending' && $canAdmin('order.cancel'))
                <form method="post" action="{{ route('admin.orders.cancel', $order) }}" class="mt-5 space-y-3">
                    @csrf
                    <label class="block text-sm">
                        取消原因
                        <input class="mt-1 w-full rounded border px-3 py-2" name="reason" value="后台取消">
                    </label>
                    <button class="w-full rounded border border-red-300 px-4 py-2 text-red-700">取消订单</button>
                </form>
            @endif

            @if (
                $order->status !== 'Pending'
                || (!$clientDeleted && !$canAdmin('order.approve') && !$canAdmin('order.cancel'))
                || ($clientDeleted && !$canAdmin('order.cancel'))
            )
                <p class="text-sm text-slate-500">当前没有可执行的订单操作。</p>
            @endif
        </div>
    </div>
@endsection
