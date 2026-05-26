@extends('layouts.admin')

@section('title', '财务对账')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">财务对账</h1>
    </div>

    <form method="post" action="{{ route('admin.financial-statements.generate') }}" class="mb-6 grid gap-4 rounded bg-white p-6 shadow-sm md:grid-cols-4">
        @csrf
        <label class="text-sm">
            <span class="text-slate-600">开始日期</span>
            <input class="mt-1 w-full rounded border px-3 py-2" type="date" name="period_start" value="{{ old('period_start', now()->subMonthNoOverflow()->startOfMonth()->toDateString()) }}" required>
        </label>
        <label class="text-sm">
            <span class="text-slate-600">结束日期</span>
            <input class="mt-1 w-full rounded border px-3 py-2" type="date" name="period_end" value="{{ old('period_end', now()->subMonthNoOverflow()->endOfMonth()->toDateString()) }}" required>
        </label>
        <div class="flex items-end md:col-span-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">生成报表</button>
        </div>
    </form>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y">
            <thead class="bg-slate-50 text-left text-sm text-slate-600">
                <tr>
                    <th class="px-4 py-3">期间</th>
                    <th class="px-4 py-3">总收入</th>
                    <th class="px-4 py-3">退款</th>
                    <th class="px-4 py-3">佣金</th>
                    <th class="px-4 py-3">净收入</th>
                    <th class="px-4 py-3">账单/退款</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y text-sm">
                @forelse ($statements as $statement)
                    <tr>
                        <td class="px-4 py-3">{{ $statement->period_start?->toDateString() }} 至 {{ $statement->period_end?->toDateString() }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $statement->total_income, 2) }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $statement->total_refund, 2) }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $statement->total_commission, 2) }}</td>
                        <td class="px-4 py-3 font-medium">{{ number_format((float) $statement->net_income, 2) }}</td>
                        <td class="px-4 py-3">{{ $statement->paid_invoice_count }} / {{ $statement->refund_count }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.financial-statements.show', $statement) }}">查看</a>
                            <a class="ml-3 text-blue-600" href="{{ route('admin.financial-statements.export', $statement) }}">导出</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无财务对账报表</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $statements->links() }}</div>
@endsection
