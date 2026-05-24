@extends('layouts.admin')

@section('title', '服务详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">服务 #{{ $host->id }}</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.hosts.index') }}">返回列表</a>
    </div>

    @if (session('error'))
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded bg-white p-5 shadow-sm lg:col-span-2">
            <h2 class="mb-4 font-semibold">服务信息</h2>
            <dl class="grid gap-3 text-sm md:grid-cols-2">
                <div><dt class="text-slate-500">客户</dt><dd>{{ $host->client?->username }} / {{ $host->client?->email }}</dd></div>
                <div><dt class="text-slate-500">产品</dt><dd>{{ $host->product?->name }}</dd></div>
                <div><dt class="text-slate-500">服务器模块</dt><dd>{{ $host->product?->server_type ?: '未绑定' }}</dd></div>
                <div>
                    <dt class="text-slate-500">状态</dt>
                    <dd>
                        {{ $host->status }}
                        @if ($failureLog && $host->status === 'Pending')
                            <span class="ml-2 inline-flex rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">开通失败</span>
                        @endif
                    </dd>
                </div>
                <div><dt class="text-slate-500">域名</dt><dd>{{ $host->domain ?: '-' }}</dd></div>
                <div><dt class="text-slate-500">用户名</dt><dd>{{ $host->username ?: '-' }}</dd></div>
                <div><dt class="text-slate-500">账期</dt><dd>{{ $host->billing_cycle }}</dd></div>
                <div><dt class="text-slate-500">续费金额</dt><dd>{{ $host->recurring_amount }}</dd></div>
                <div><dt class="text-slate-500">开通时间</dt><dd>{{ $host->registered_at?->format('Y-m-d H:i') ?: '-' }}</dd></div>
                <div><dt class="text-slate-500">到期时间</dt><dd>{{ $host->next_due_date?->format('Y-m-d H:i') ?: '-' }}</dd></div>
            </dl>
        </section>

        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">后台操作</h2>
            <div class="space-y-3 text-sm">
                @if (in_array($host->status, ['Pending', 'Suspended'], true))
                    <form method="post" action="{{ route('admin.hosts.action', $host) }}">
                        @csrf
                        <input type="hidden" name="action" value="provision">
                        <button class="w-full rounded bg-slate-900 px-4 py-2 text-white">{{ $failureLog ? '重试开通' : '开通服务' }}</button>
                    </form>
                @endif
                @if ($host->status === 'Active')
                    <form method="post" action="{{ route('admin.hosts.action', $host) }}">
                        @csrf
                        <input type="hidden" name="action" value="suspend">
                        <input class="mb-2 w-full rounded border px-3 py-2" name="reason" placeholder="暂停原因">
                        <button class="w-full rounded bg-amber-600 px-4 py-2 text-white">暂停</button>
                    </form>
                @endif
                @if ($host->status === 'Suspended')
                    <form method="post" action="{{ route('admin.hosts.action', $host) }}">
                        @csrf
                        <input type="hidden" name="action" value="unsuspend">
                        <button class="w-full rounded bg-emerald-700 px-4 py-2 text-white">解除暂停</button>
                    </form>
                @endif
                @if (in_array($host->status, ['Active', 'Suspended'], true))
                    <form method="post" action="{{ route('admin.hosts.action', $host) }}">
                        @csrf
                        <input type="hidden" name="action" value="reset_password">
                        <button class="w-full rounded border px-4 py-2">重置密码</button>
                    </form>
                    <form method="post" action="{{ route('admin.hosts.action', $host) }}">
                        @csrf
                        <input type="hidden" name="action" value="terminate">
                        <button class="w-full rounded bg-red-600 px-4 py-2 text-white">终止</button>
                    </form>
                @endif
            </div>
        </section>
    </div>

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">用量统计</h2>
        @if (!empty($usageStats))
            <dl class="grid gap-3 text-sm md:grid-cols-4">
                <div><dt class="text-slate-500">CPU</dt><dd>{{ $usageStats['cpu'] ?? '-' }}%</dd></div>
                <div><dt class="text-slate-500">内存</dt><dd>{{ $usageStats['memory'] ?? '-' }} MB</dd></div>
                <div><dt class="text-slate-500">磁盘</dt><dd>{{ $usageStats['disk'] ?? '-' }} GB</dd></div>
                <div><dt class="text-slate-500">带宽</dt><dd>{{ $usageStats['bandwidth'] ?? '-' }} GB</dd></div>
            </dl>
        @else
            <p class="text-sm text-slate-500">当前服务未绑定可读取用量的服务器模块。</p>
        @endif
    </section>

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">最近用量记录</h2>
        <div class="overflow-hidden rounded border">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3">采集时间</th>
                        <th class="px-4 py-3">CPU</th>
                        <th class="px-4 py-3">内存</th>
                        <th class="px-4 py-3">磁盘</th>
                        <th class="px-4 py-3">带宽</th>
                        <th class="px-4 py-3">错误</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($host->usageSnapshots as $snapshot)
                        <tr>
                            <td class="px-4 py-3">{{ $snapshot->collected_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3">{{ $snapshot->cpu !== null ? $snapshot->cpu . '%' : '-' }}</td>
                            <td class="px-4 py-3">{{ $snapshot->memory !== null ? $snapshot->memory . ' MB' : '-' }}</td>
                            <td class="px-4 py-3">{{ $snapshot->disk !== null ? $snapshot->disk . ' GB' : '-' }}</td>
                            <td class="px-4 py-3">{{ $snapshot->bandwidth !== null ? $snapshot->bandwidth . ' GB' : '-' }}</td>
                            <td class="px-4 py-3">{{ $snapshot->error ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无用量记录</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($failureLog)
        <section class="mt-6 rounded border border-amber-200 bg-amber-50 p-5">
            <h2 class="mb-4 font-semibold text-amber-900">开通失败原因</h2>
            <p class="text-sm text-amber-900">{{ $failureLog->message ?: '服务开通失败' }}</p>
            <p class="mt-2 text-xs text-amber-700">最近一次失败时间：{{ $failureLog->created_at?->format('Y-m-d H:i:s') }}</p>
        </section>
    @endif

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">订单与账单</h2>
        <div class="text-sm">
            <p>订单：{{ $host->order?->order_number ?: '-' }} / {{ $host->order?->status ?: '-' }}</p>
            <p>账单：{{ $host->order?->invoice?->invoice_number ?: '-' }} / {{ $host->order?->invoice?->status ?: '-' }}</p>
        </div>
    </section>

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">操作日志</h2>
        <div class="overflow-hidden rounded border">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3">时间</th>
                        <th class="px-4 py-3">动作</th>
                        <th class="px-4 py-3">说明</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($host->actionLogs as $log)
                        <tr>
                            <td class="px-4 py-3">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3">{{ $log->action }}</td>
                            <td class="px-4 py-3">{{ $log->message }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-6 text-center text-slate-500" colspan="3">暂无操作日志</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
