@extends('layouts.admin')

@section('title', '财务对账详情')

@section('content')
    @php($breakdown = is_array($statement->breakdown) ? $statement->breakdown : [])
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">财务对账详情</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $statement->period_start?->toDateString() }} 至 {{ $statement->period_end?->toDateString() }}</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.financial-statements.export', $statement) }}">导出 Excel</a>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-5">
        @foreach ([
            '总收入' => $statement->total_income,
            '总退款' => $statement->total_refund,
            '总佣金' => $statement->total_commission,
            '净收入' => $statement->net_income,
            '账单数' => $statement->paid_invoice_count,
        ] as $label => $value)
            <div class="rounded bg-white p-4 shadow-sm">
                <div class="text-sm text-slate-500">{{ $label }}</div>
                <div class="mt-2 text-xl font-semibold">{{ is_numeric($value) ? number_format((float) $value, $label === '账单数' ? 0 : 2) : $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">按支付方式统计</h2>
            <table class="min-w-full divide-y text-sm">
                <thead class="text-left text-slate-500">
                    <tr>
                        <th class="py-2">支付方式</th>
                        <th class="py-2">笔数</th>
                        <th class="py-2">金额</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse (($breakdown['income_by_payment_method'] ?? []) as $item)
                        <tr>
                            <td class="py-2">{{ $item['payment_method'] ?? 'unknown' }}</td>
                            <td class="py-2">{{ $item['count'] ?? 0 }}</td>
                            <td class="py-2">{{ number_format((float) ($item['total'] ?? 0), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-center text-slate-500" colspan="3">暂无收入流水</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">按产品统计</h2>
            <table class="min-w-full divide-y text-sm">
                <thead class="text-left text-slate-500">
                    <tr>
                        <th class="py-2">产品</th>
                        <th class="py-2">数量</th>
                        <th class="py-2">金额</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse (($breakdown['income_by_product'] ?? []) as $item)
                        <tr>
                            <td class="py-2">{{ $item['product_name'] ?? '未知产品' }}</td>
                            <td class="py-2">{{ $item['count'] ?? 0 }}</td>
                            <td class="py-2">{{ number_format((float) ($item['total'] ?? 0), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-center text-slate-500" colspan="3">暂无产品收入</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection
