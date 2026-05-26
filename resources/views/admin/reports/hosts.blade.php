@extends('layouts.admin')

@section('title', '服务状态报表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">服务状态报表</h1>
            <p class="mt-1 text-sm text-slate-500">统计服务当前状态分布。</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.reports.index') }}">返回报表</a>
    </div>

    <section class="rounded bg-white p-5 shadow-sm">
        <div class="h-80">
            <canvas id="hostsChart"></canvas>
        </div>
    </section>

    @include('admin.reports._chart', [
        'chartId' => 'hostsChart',
        'type' => 'pie',
        'label' => '服务数量',
        'labels' => $labels,
        'values' => $values,
    ])
@endsection
