@extends('layouts.admin')

@section('title', '客户详情')

@section('content')
    @php($adminUser = auth('admin')->user())
    @php($canAdmin = fn (string $permission): bool => $adminUser && ($adminUser->hasRole('super-admin') || $adminUser->can($permission)))

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $client->username }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $client->email }}</p>
        </div>
        <div class="flex gap-2 text-sm">
            @if ($canAdmin('invoice.view'))
                <a class="rounded border px-4 py-2" href="{{ route('admin.invoices.index') }}?keyword={{ $client->username }}">查看账单</a>
            @endif
            @if ($canAdmin('order.view'))
                <a class="rounded border px-4 py-2" href="{{ route('admin.orders.index') }}?keyword={{ $client->username }}">查看订单</a>
            @endif
            @if ($canAdmin('ticket.view'))
                <a class="rounded border px-4 py-2" href="{{ route('admin.tickets.index') }}?keyword={{ $client->username }}">查看工单</a>
            @endif
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
                <div>
                    登录锁定：
                    @if ($client->locked_until && $client->locked_until->isFuture())
                        <span class="font-medium text-red-600">锁定至 {{ $client->locked_until->format('Y-m-d H:i:s') }}</span>
                    @else
                        <span class="text-slate-500">未锁定</span>
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">账户概览</h2>
            <div class="space-y-3 text-sm">
                <div>状态：{{ $client->status }}</div>
                <div>余额：{{ $client->credit }}</div>
                <div>信用额度：{{ $client->credit_limit }}</div>
                <div>可用额度：{{ number_format($client->availableCredit(), 2, '.', '') }}</div>
                <div>邮箱：{{ $client->email }}</div>
                <div>锁定状态：{{ $client->locked_until && $client->locked_until->isFuture() ? '已锁定' : '正常' }}</div>
                <div>
                    未完结服务：
                    {{ $client->hosts->whereIn('status', \App\Modules\User\Services\ClientService::BLOCKING_HOST_STATUSES)->count() }}
                </div>
            </div>
            @if ($canAdmin('client.manage') && $client->locked_until && $client->locked_until->isFuture())
                <form method="post" action="{{ route('admin.clients.unlock', $client) }}" class="mt-5 border-t pt-5">
                    @csrf
                    <button class="rounded bg-emerald-600 px-4 py-2 text-sm text-white">解锁账户</button>
                </form>
            @endif
            @if ($canAdmin('client.credit'))
                <form method="post" action="{{ route('admin.clients.credit-limit', $client) }}" class="mt-5 border-t pt-5">
                    @csrf
                    <label class="block text-sm">
                        <span class="font-medium text-slate-700">修改信用额度</span>
                        <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="credit_limit" min="0" max="99999999.99" step="0.01" value="{{ old('credit_limit', $client->credit_limit) }}" required>
                    </label>
                    @error('credit_limit')
                        <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                    @enderror
                    <button class="mt-3 rounded bg-slate-900 px-4 py-2 text-sm text-white">保存信用额度</button>
                </form>
            @endif
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

    @if ($canAdmin('invoice.view') || $canAdmin('order.view') || $canAdmin('ticket.view'))
        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            @if ($canAdmin('invoice.view'))
                <section class="rounded bg-white p-6 shadow-sm">
                    <h2 class="mb-4 font-semibold">账单</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($client->invoices->take(5) as $invoice)
                            <li>{{ $invoice->invoice_number }} / {{ $invoice->status }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($canAdmin('order.view'))
                <section class="rounded bg-white p-6 shadow-sm">
                    <h2 class="mb-4 font-semibold">订单</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($client->orders->take(5) as $order)
                            <li>{{ $order->order_number }} / {{ $order->status }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($canAdmin('ticket.view'))
                <section class="rounded bg-white p-6 shadow-sm">
                    <h2 class="mb-4 font-semibold">工单</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($client->tickets->take(5) as $ticket)
                            <li>{{ $ticket->ticket_number }} / {{ $ticket->status?->name ?? $ticket->status_id }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
@endsection
