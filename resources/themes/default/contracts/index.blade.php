@extends('theme::layouts.app')

@section('title', '我的合同')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">我的合同</h1>

    <section class="rounded bg-white p-6 shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-600">
                <tr>
                    <th class="px-4 py-3">合同</th>
                    <th class="px-4 py-3">关联订单</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">签署时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($contracts as $contract)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $contract->title }}</td>
                        <td class="px-4 py-3">{{ $contract->order?->order_number ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $contract->status }}</td>
                        <td class="px-4 py-3">{{ userTime($contract->signed_at) ?: '-' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('client.contracts.show', $contract) }}">查看</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-zinc-500" colspan="5">暂无合同</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-4">{{ $contracts->links() }}</div>
    </section>
@endsection
