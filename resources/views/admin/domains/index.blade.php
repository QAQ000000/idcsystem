@extends('layouts.admin')

@section('title', '域名管理')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">域名管理</h1>
            <p class="mt-1 text-sm text-slate-500">查看客户域名、状态和到期时间。</p>
        </div>
        @can('domain.manage')
            <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.domain-pricings.index') }}">TLD 价格</a>
        @endcan
    </div>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-4">
        <label class="block text-sm">
            状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部状态</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            客户
            <select class="mt-1 w-full rounded border px-3 py-2" name="client_id">
                <option value="">全部客户</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->username }} / {{ $client->email }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.domains.index') }}">重置</a>
        </div>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">域名</th>
                    <th class="px-4 py-3">客户</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">到期日</th>
                    <th class="px-4 py-3">注册商</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($domains as $domain)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $domain->domain }}</td>
                        <td class="px-4 py-3">{{ $domain->client?->username }} / {{ $domain->client?->email }}</td>
                        <td class="px-4 py-3">{{ $domain->status }}</td>
                        <td class="px-4 py-3">{{ $domain->expiry_date?->format('Y-m-d') }}</td>
                        <td class="px-4 py-3">{{ $domain->registrar ?: 'manual' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="5">暂无域名</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $domains->links() }}</div>
@endsection
