@extends('layouts.client')

@section('title', '服务详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $host->product?->name ?: '服务详情' }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $host->domain ?: '未绑定域名' }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.hosts.index') }}">返回服务列表</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="mb-4 font-semibold">服务信息</h2>
            <div class="grid gap-4 text-sm md:grid-cols-2">
                <div>状态：{{ $host->status }}</div>
                <div>计费周期：{{ $host->billing_cycle }}</div>
                <div>首次金额：{{ $host->first_payment_amount }}</div>
                <div>续费金额：{{ $host->recurring_amount }}</div>
                <div>开通时间：{{ $host->registered_at?->format('Y-m-d H:i:s') ?: '-' }}</div>
                <div>到期时间：{{ $host->next_due_date?->format('Y-m-d H:i:s') ?: '-' }}</div>
                <div>下次账单：{{ $host->next_invoice_date?->format('Y-m-d H:i:s') ?: '-' }}</div>
                <div>自动续费：{{ $host->auto_renew ? '开启' : '关闭' }}</div>
                <div>绑定账单：{{ $host->order?->invoice?->invoice_number ?: '-' }}</div>
                <div>暂停原因：{{ $host->suspend_reason ?: '-' }}</div>
            </div>
            <div class="mt-5 rounded border bg-zinc-50 p-4 text-sm leading-7">
                {!! nl2br(e($host->product?->description ?: '暂无产品说明')) !!}
            </div>
        </section>

        <aside class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">续费</h2>
            @if (!in_array($host->status, ['Terminated', 'Cancelled'], true))
                <form method="post" action="{{ route('client.hosts.renew', $host) }}" class="space-y-3">
                    @csrf
                    <label class="block text-sm">
                        续费周期
                        <select class="mt-1 w-full rounded border px-3 py-2" name="billing_cycle">
                            @foreach ($cycles as $cycle)
                                <option value="{{ $cycle }}" @selected($host->billing_cycle === $cycle)>{{ $cycle }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="w-full rounded bg-zinc-900 px-4 py-2 text-white">生成续费账单</button>
                </form>
            @else
                <p class="text-sm text-zinc-500">当前状态不可续费。</p>
            @endif

            <h2 class="mb-4 mt-6 font-semibold">升级/降配</h2>
            @if ($host->status === 'Active')
                <form method="post" action="{{ route('client.hosts.upgrade', $host) }}" class="space-y-3">
                    @csrf
                    <label class="block text-sm">
                        目标产品
                        <select class="mt-1 w-full rounded border px-3 py-2" name="product_id">
                            @foreach ($upgradeProducts as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="w-full rounded border px-4 py-2">生成调整账单</button>
                </form>
            @else
                <p class="text-sm text-zinc-500">仅 Active 状态可调整配置。</p>
            @endif
        </aside>
    </div>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">服务操作</h2>
        <div class="flex flex-wrap gap-3 text-sm">
            @foreach ([
                'provision' => ['开通', $host->status === 'Pending'],
                'suspend' => ['暂停', $host->status === 'Active'],
                'unsuspend' => ['解除暂停', $host->status === 'Suspended'],
                'reboot' => ['重启', $host->status === 'Active'],
                'reset_password' => ['重置密码', in_array($host->status, ['Active', 'Suspended'], true)],
                'cancel_auto_renew' => ['取消自动续费', $host->auto_renew],
            ] as $action => [$label, $enabled])
                <form method="post" action="{{ route('client.hosts.action', $host) }}">
                    @csrf
                    <input type="hidden" name="action" value="{{ $action }}">
                    <button class="rounded border px-4 py-2 disabled:cursor-not-allowed disabled:opacity-50" @disabled(!$enabled)>{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </section>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">最近操作</h2>
        <div class="divide-y">
            @forelse ($host->actionLogs as $log)
                <div class="py-3 text-sm">
                    <div class="font-medium">{{ $log->message ?: $log->action }}</div>
                    <div class="text-zinc-500">{{ $log->action }} · {{ $log->created_at?->format('Y-m-d H:i:s') }}</div>
                </div>
            @empty
                <p class="text-sm text-zinc-500">暂无操作记录</p>
            @endforelse
        </div>
    </section>
@endsection
