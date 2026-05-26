@extends('theme::layouts.app')

@section('title', '用量告警')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">用量告警</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $host->product?->name ?: '服务' }} / #{{ $host->id }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.hosts.show', $host) }}">返回服务详情</a>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">告警规则</h2>
            <div class="divide-y divide-zinc-100">
                @forelse ($host->usageAlerts as $alert)
                    <div class="flex items-center justify-between py-4 text-sm">
                        <div>
                            <div class="font-medium">{{ $metrics[$alert->metric] ?? $alert->metric }} >= {{ $alert->threshold }}%</div>
                            <div class="mt-1 text-zinc-500">
                                {{ $alert->active ? '启用' : '停用' }} / 最近触发 {{ $alert->last_triggered_at?->format('Y-m-d H:i:s') ?: '-' }}
                            </div>
                        </div>
                        <form method="post" action="{{ route('client.hosts.alerts.destroy', [$host, $alert]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600" onclick="return confirm('确定删除该告警？')">删除</button>
                        </form>
                    </div>
                @empty
                    <p class="py-6 text-sm text-zinc-500">暂无告警规则</p>
                @endforelse
            </div>
        </section>

        <aside class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">新增或更新规则</h2>
            <form method="post" action="{{ route('client.hosts.alerts.store', $host) }}" class="space-y-4">
                @csrf
                <label class="block text-sm">
                    指标
                    <select class="mt-1 w-full rounded border px-3 py-2" name="metric">
                        @foreach ($metrics as $key => $label)
                            <option value="{{ $key }}" @selected(old('metric') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    阈值（%）
                    <input class="mt-1 w-full rounded border px-3 py-2" type="number" min="1" max="100" name="threshold" value="{{ old('threshold', 80) }}" required>
                </label>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="active" value="1" @checked(old('active', true))>
                    启用
                </label>
                <button class="w-full rounded bg-zinc-900 px-4 py-2 text-white">保存告警</button>
            </form>

            <div class="mt-6 rounded border bg-zinc-50 p-4 text-sm">
                <h3 class="mb-3 font-semibold">最近采集</h3>
                @if ($latestSnapshot)
                    <dl class="space-y-2">
                        @foreach ($metrics as $key => $label)
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">{{ $label }}</dt>
                                <dd>{{ $latestSnapshot->{$key} !== null ? $latestSnapshot->{$key} . '%' : '-' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <p class="text-zinc-500">暂无用量记录</p>
                @endif
            </div>
        </aside>
    </div>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">触发记录</h2>
        <div class="overflow-hidden rounded border">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-zinc-600">
                    <tr>
                        <th class="px-4 py-3">时间</th>
                        <th class="px-4 py-3">指标</th>
                        <th class="px-4 py-3">当前值</th>
                        <th class="px-4 py-3">阈值</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($host->usageAlertLogs as $log)
                        <tr>
                            <td class="px-4 py-3">{{ $log->triggered_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3">{{ $metrics[$log->metric] ?? $log->metric }}</td>
                            <td class="px-4 py-3">{{ number_format((float) $log->current_value, 2) }}%</td>
                            <td class="px-4 py-3">{{ $log->threshold }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-6 text-center text-zinc-500" colspan="4">暂无触发记录</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
