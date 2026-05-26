@extends('layouts.admin')

@section('title', '产品销售排行')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">产品销售排行</h1>
            <p class="mt-1 text-sm text-slate-500">按已支付账单统计产品销售数量和金额。</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.reports.index') }}">返回报表</a>
    </div>

    <section class="rounded bg-white p-5 shadow-sm">
        <div class="h-80">
            <canvas id="productsChart"></canvas>
        </div>
        <table class="mt-6 min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">产品</th>
                    <th class="px-4 py-3">销售数量</th>
                    <th class="px-4 py-3">销售金额</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($items as $item)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $item['name'] }}</td>
                        <td class="px-4 py-3">{{ $item['sales_count'] }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $item['revenue'], 2, '.', '') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="3">暂无销售数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @include('admin.reports._chart', [
        'chartId' => 'productsChart',
        'type' => 'bar',
        'label' => '销售金额',
        'labels' => $labels,
        'values' => $values,
        'backgroundColor' => ['#2563eb'],
    ])
@endsection
