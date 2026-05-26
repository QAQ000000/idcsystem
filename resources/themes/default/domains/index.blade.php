@extends('theme::layouts.app')

@section('title', '域名')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">我的域名</h1>
            <p class="mt-1 text-sm text-zinc-500">管理域名状态、DNS 服务器和续费账单。</p>
        </div>
        <a class="rounded bg-zinc-900 px-4 py-2 text-sm text-white" href="{{ route('client.domains.register') }}">注册域名</a>
    </div>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-600">
                <tr>
                    <th class="px-4 py-3">域名</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">到期日</th>
                    <th class="px-4 py-3">自动续费</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($domains as $domain)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $domain->domain }}</td>
                        <td class="px-4 py-3">{{ $domain->status }}</td>
                        <td class="px-4 py-3">{{ $domain->expiry_date?->format('Y-m-d') }}</td>
                        <td class="px-4 py-3">{{ $domain->auto_renew ? '开启' : '关闭' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('client.domains.show', $domain) }}">管理</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-zinc-500" colspan="5">暂无域名</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $domains->links() }}</div>
@endsection
