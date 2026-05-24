@extends('layouts.admin')

@section('title', '客户详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $client->username }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $client->email }}</p>
        </div>
        <div class="flex gap-2 text-sm">
            <a class="rounded border px-4 py-2" href="{{ route('admin.invoices.index') }}?keyword={{ $client->username }}">查看账单</a>
            <a class="rounded border px-4 py-2" href="{{ route('admin.orders.index') }}?keyword={{ $client->username }}">查看订单</a>
            <a class="rounded border px-4 py-2" href="{{ route('admin.tickets.index') }}?keyword={{ $client->username }}">查看工单</a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="mb-4 font-semibold">客户资料</h2>
            <div class="grid gap-4 md:grid-cols-2 text-sm">
                <div>公司名称：{{ $client->company_name ?: '-' }}</div>
                <div>手机号：{{ trim($client->phone_code . ' ' . $client->phone) ?: '-' }}</div>
                <div>地址：{{ trim(($client->country ?: '') . ' ' . ($client->province ?: '') . ' ' . ($client->city ?: '') . ' ' . ($client->address ?: '')) ?: '-' }}</div>
                <div>最近登录：{{ $client->last_login_at?->format('Y-m-d H:i:s') ?: '-' }}</div>
                <div>最近登录 IP：{{ $client->last_login_ip ?: '-' }}</div>
                <div>两步验证：{{ $client->two_factor_enabled ? '已开启' : '未开启' }}</div>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">账户概览</h2>
            <div class="space-y-3 text-sm">
                <div>状态：{{ $client->status }}</div>
                <div>余额：{{ $client->credit }}</div>
                <div>信用额度：{{ $client->credit_limit }}</div>
                <div>邮箱：{{ $client->email }}</div>
            </div>
        </section>
    </div>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">登录记录</h2>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">IP</th>
                    <th class="px-4 py-3">设备</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($client->loginLogs as $log)
                    <tr>
                        <td class="px-4 py-3">{{ $log->logged_in_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $log->ip ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $log->user_agent ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="3">暂无登录记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">账单</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($client->invoices->take(5) as $invoice)
                    <li>{{ $invoice->invoice_number }} / {{ $invoice->status }}</li>
                @endforeach
            </ul>
        </section>
        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">订单</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($client->orders->take(5) as $order)
                    <li>{{ $order->order_number }} / {{ $order->status }}</li>
                @endforeach
            </ul>
        </section>
        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">工单</h2>
            <ul class="space-y-2 text-sm">
                @foreach ($client->tickets->take(5) as $ticket)
                    <li>{{ $ticket->ticket_number }} / {{ $ticket->status?->name ?? $ticket->status_id }}</li>
                @endforeach
            </ul>
        </section>
    </div>
@endsection
