@extends('layouts.admin')

@section('title', '服务管理')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">服务管理</h1>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-4">
        <label class="block text-sm">
            客户
            <select class="mt-1 w-full rounded border px-3 py-2" name="client_id">
                <option value="">全部客户</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected((string) ($filters['client_id'] ?? '') === (string) $client->id)>
                        {{ $client->username }} / {{ $client->email }}
                    </option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            产品
            <select class="mt-1 w-full rounded border px-3 py-2" name="product_id">
                <option value="">全部产品</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $product->id)>{{ $product->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部状态</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.hosts.index') }}">重置</a>
        </div>
    </form>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">服务</th>
                    <th class="px-4 py-3">客户</th>
                    <th class="px-4 py-3">产品</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">到期时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($hosts as $host)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">#{{ $host->id }} {{ $host->domain ?: '未绑定域名' }}</div>
                            <div class="text-slate-500">{{ $host->billing_cycle }} / {{ $host->recurring_amount }}</div>
                            @if ($host->actionLogs->first(fn ($log) => str_ends_with((string) $log->action, '_failed')))
                                <div class="mt-1 inline-flex rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">有失败</div>
                            @elseif ($host->status === 'Pending')
                                <div class="mt-1 inline-flex rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">待开通</div>
                            @elseif ($host->status === 'Active')
                                <div class="mt-1 inline-flex rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">运行中</div>
                            @elseif ($host->status === 'Suspended')
                                <div class="mt-1 inline-flex rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-700">已暂停</div>
                            @elseif ($host->status === 'Terminated')
                                <div class="mt-1 inline-flex rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">已终止</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $host->client?->username }}</td>
                        <td class="px-4 py-3">{{ $host->product?->name }}</td>
                        <td class="px-4 py-3">{{ $host->status }}</td>
                        <td class="px-4 py-3">{{ $host->next_due_date?->format('Y-m-d H:i') ?: '-' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.hosts.show', $host) }}">查看</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无服务</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $hosts->links() }}</div>
@endsection
