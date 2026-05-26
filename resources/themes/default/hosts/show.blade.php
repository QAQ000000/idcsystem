@extends('theme::layouts.app')

@section('title', '服务详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $host->product?->name ?: '服务详情' }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $host->domain ?: '未绑定域名' }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.hosts.index') }}">返回服务列表</a>
    </div>
    <div class="mb-6 flex flex-wrap gap-3 text-sm">
        <a class="rounded border px-4 py-2" href="{{ route('client.hosts.alerts.index', $host) }}">用量告警</a>
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
            @if ($host->customFieldValues->isNotEmpty())
                <div class="mt-5 rounded border bg-zinc-50 p-4 text-sm">
                    <h3 class="mb-3 font-semibold">自定义信息</h3>
                    <dl class="grid gap-3 md:grid-cols-2">
                        @foreach ($host->customFieldValues as $value)
                            <div>
                                <dt class="text-zinc-500">{{ $value->field?->field_name ?: '字段 #' . $value->field_id }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap">{{ $value->value ?: '-' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </section>

        <section class="rounded bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="mb-4 font-semibold">附加项</h2>
            <div class="divide-y divide-zinc-100">
                @forelse ($host->addons as $hostAddon)
                    <div class="flex items-center justify-between py-3 text-sm">
                        <div>
                            <div class="font-medium">{{ $hostAddon->addon?->name ?: 'Addon #' . $hostAddon->addon_id }}</div>
                            <div class="text-zinc-500">{{ $hostAddon->billing_cycle }} / {{ $hostAddon->status }} / 到期 {{ $hostAddon->next_due_date?->format('Y-m-d') ?: '-' }}</div>
                        </div>
                        <div>{{ number_format((float) $hostAddon->price, 2) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">暂无附加项</p>
                @endforelse
            </div>
            @php
                $activeAddonIds = $host->addons->where('status', '!=', 'Terminated')->pluck('addon_id')->map(fn ($id) => (int) $id)->all();
                $availableAddons = $host->product?->addons?->whereNotIn('id', $activeAddonIds) ?? collect();
            @endphp
            @if (in_array($host->status, ['Active', 'Suspended'], true) && $availableAddons->isNotEmpty())
                <form method="post" action="{{ route('client.hosts.addons.store', $host) }}" class="mt-5 flex flex-wrap items-end gap-3 border-t pt-4">
                    @csrf
                    <label class="block text-sm">
                        添加附加项
                        <select class="mt-1 rounded border px-3 py-2" name="addon_id">
                            @foreach ($availableAddons as $addon)
                                <option value="{{ $addon->id }}">{{ $addon->name }} / {{ $addon->billing_cycle === 'recurring' ? '周期' : '一次性' }} / {{ number_format((float) $addon->price, 2) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="rounded bg-zinc-900 px-4 py-2 text-sm text-white">添加附加项</button>
                </form>
            @endif
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

            <h2 class="mb-4 mt-6 font-semibold">取消申请</h2>
            @php($pendingCancelRequest = $host->pendingCancelRequest)
            @if ($pendingCancelRequest)
                <div class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    已提交取消申请，类型：{{ $pendingCancelRequest->type }}，请等待管理员审核。
                </div>
            @elseif (in_array($host->status, ['Active', 'Suspended'], true))
                <form method="post" action="{{ route('client.hosts.cancel', $host) }}" class="space-y-3">
                    @csrf
                    <label class="block text-sm">
                        取消类型
                        <select class="mt-1 w-full rounded border px-3 py-2" name="type">
                            <option value="end_of_billing_period">到期取消</option>
                            <option value="immediate">立即取消</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        原因
                        <textarea class="mt-1 w-full rounded border px-3 py-2" name="reason" rows="3"></textarea>
                    </label>
                    <button class="w-full rounded border border-red-300 px-4 py-2 text-red-700" onclick="return confirm('确定提交取消申请？')">提交取消申请</button>
                </form>
            @else
                <p class="text-sm text-zinc-500">当前状态不可申请取消。</p>
            @endif
        </aside>
    </div>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">服务操作</h2>
        <div class="flex flex-wrap gap-3 text-sm">
            @foreach ([
                'provision' => ['开通', $host->status === 'Pending' && $host->order?->status === 'Paid' && (!$host->order?->invoice || $host->order->invoice->status === 'Paid')],
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
