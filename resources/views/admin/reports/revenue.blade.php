@extends('layouts.admin')

@section('title', '收入报表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">收入报表</h1>
            <p class="mt-1 text-sm text-slate-500">按月统计已支付账单总额。</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.reports.index') }}">返回报表</a>
    </div>

    <section class="rounded bg-white p-5 shadow-sm">
        <div class="h-80">
            <canvas id="revenueChart"></canvas>
        </div>
    </section>

    @include('admin.reports._chart', [
        'chartId' => 'revenueChart',
        'type' => 'line',
        'label' => '收入',
        'labels' => $labels,
        'values' => $values,
        'borderColor' => '#2563eb',
        'backgroundColor' => ['rgba(37, 99, 235, 0.16)'],
    ])
@endsection
