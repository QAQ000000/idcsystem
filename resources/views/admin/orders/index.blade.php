@extends('layouts.admin')

@section('title', '订单列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">订单列表</h1>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">ID</th>
                    <th class="px-4 py-3 font-medium">订单号</th>
                    <th class="px-4 py-3 font-medium">状态</th>
                    <th class="px-4 py-3 font-medium">金额</th>
                    <th class="px-4 py-3 font-medium">客户</th>
                    <th class="px-4 py-3 font-medium">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $order)
                    <tr>
                        <td class="px-4 py-3">{{ $order->id }}</td>
                        <td class="px-4 py-3">{{ $order->order_number }}</td>
                        <td class="px-4 py-3">{{ $order->status }}</td>
                        <td class="px-4 py-3">{{ $order->amount }}</td>
                        <td class="px-4 py-3">
                            {{ $order->client?->username ?: '-' }}
                            @if ($order->client?->trashed())
                                <span class="ml-1 inline-flex rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-700">已删除</span>
                            @endif
                        </td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.orders.show', $order) }}">查看</a></td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $orders->links() }}</div>
@endsection
