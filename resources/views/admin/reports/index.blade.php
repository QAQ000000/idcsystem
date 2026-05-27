@extends('layouts.admin')

@section('title', '运营报表')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">运营报表</h1>
        <p class="mt-1 text-sm text-slate-500">查看收入、客户增长、服务状态和产品销售排行。</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['title' => '收入趋势', 'desc' => '按月统计已支付账单金额', 'route' => route('admin.reports.revenue')],
            ['title' => '客户增长', 'desc' => '按月统计新增客户数量', 'route' => route('admin.reports.clients')],
            ['title' => '服务状态', 'desc' => '统计服务当前状态分布', 'route' => route('admin.reports.hosts')],
            ['title' => '产品排行', 'desc' => '按已支付账单统计产品销售', 'route' => route('admin.reports.products')],
            ['title' => '自定义报表', 'desc' => '创建只读 SQL 报表并导出 CSV', 'route' => route('admin.reports.custom.index')],
        ] as $report)
            <a class="rounded bg-white p-5 shadow-sm hover:bg-slate-50" href="{{ $report['route'] }}">
                <div class="font-semibold">{{ $report['title'] }}</div>
                <div class="mt-2 text-sm text-slate-500">{{ $report['desc'] }}</div>
            </a>
        @endforeach
    </div>
@endsection
