@extends('theme::layouts.app')

@section('title', '推介计划')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">推介计划</h1>
        <div class="flex gap-2">
            <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.affiliate.leaderboard') }}">排行榜</a>
            <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.account.profile') }}">账户资料</a>
        </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-zinc-500">推介人数</div>
            <div class="mt-1 text-2xl font-semibold">{{ $affiliate->referral_count }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-zinc-500">可提现佣金</div>
            <div class="mt-1 text-2xl font-semibold">{{ $affiliate->balance }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-zinc-500">已提现佣金</div>
            <div class="mt-1 text-2xl font-semibold">{{ $affiliate->withdrawn }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-zinc-500">状态</div>
            <div class="mt-1 text-2xl font-semibold">{{ $affiliate->status === 'active' ? '正常' : '停用' }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-zinc-500">点击数</div>
            <div class="mt-1 text-2xl font-semibold">{{ $affiliate->total_clicks }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-zinc-500">转化率</div>
            <div class="mt-1 text-2xl font-semibold">{{ $affiliate->total_clicks > 0 ? number_format($affiliate->total_signups / $affiliate->total_clicks * 100, 2) : '0.00' }}%</div>
        </div>
    </div>

    <div class="mb-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">推介链接</h2>
        <div class="grid gap-4 md:grid-cols-[1fr_auto]">
            <input class="w-full rounded border bg-zinc-50 px-3 py-2 text-sm" value="{{ $referralUrl }}" readonly>
            <form method="post" action="{{ route('client.affiliate.withdraw') }}" class="flex gap-2">
                @csrf
                <input class="w-32 rounded border px-3 py-2 text-sm" name="amount" type="number" min="0.01" step="0.01" max="{{ $affiliate->balance }}" value="{{ $affiliate->balance }}">
                <button class="rounded bg-zinc-900 px-4 py-2 text-sm text-white">提现到余额</button>
            </form>
        </div>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">推荐客户</th>
                    <th class="px-4 py-3">账单</th>
                    <th class="px-4 py-3">金额</th>
                    <th class="px-4 py-3">状态</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($affiliate->commissions->sortByDesc('id') as $commission)
                    <tr>
                        <td class="px-4 py-3">{{ $commission->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">{{ $commission->type === 'signup' ? '注册' : '付款' }}</td>
                        <td class="px-4 py-3">{{ $commission->referredClient?->username ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $commission->invoice?->invoice_number ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $commission->amount }}</td>
                        <td class="px-4 py-3">{{ $commission->status }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-zinc-500" colspan="6">暂无推介记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
