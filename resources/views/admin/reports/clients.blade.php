@extends('layouts.admin')

@section('title', '客户增长报表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">客户增长报表</h1>
            <p class="mt-1 text-sm text-slate-500">按月统计新增客户数量。</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.reports.index') }}">返回报表</a>
    </div>

    <section class="rounded bg-white p-5 shadow-sm">
        <div class="h-80">
            <canvas id="clientsChart"></canvas>
        </div>
    </section>

    @include('admin.reports._chart', [
        'chartId' => 'clientsChart',
        'type' => 'line',
        'label' => '新增客户',
        'labels' => $labels,
        'values' => $values,
        'borderColor' => '#16a34a',
        'backgroundColor' => ['rgba(22, 163, 74, 0.16)'],
    ])
@endsection
