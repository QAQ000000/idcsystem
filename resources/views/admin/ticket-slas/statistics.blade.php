@extends('layouts.admin')

@section('title', 'SLA 统计')

@section('content')
    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">SLA 统计</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.ticket-slas.index') }}">返回规则</a>
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">统计周期</div>
            <div class="mt-2 font-semibold">{{ $start->toDateString() }} 至 {{ $end->toDateString() }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">SLA 工单</div>
            <div class="mt-2 text-2xl font-semibold">{{ $statistics['total'] }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">首响达成率</div>
            <div class="mt-2 text-2xl font-semibold">{{ $statistics['response_met_rate'] }}%</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">解决达成率</div>
            <div class="mt-2 text-2xl font-semibold">{{ $statistics['resolution_met_rate'] }}%</div>
        </div>
    </div>

    <div class="mt-6 rounded bg-white p-5 shadow-sm">
        <dl class="grid gap-4 md:grid-cols-4">
            <div>
                <dt class="text-sm text-slate-500">已首响</dt>
                <dd class="mt-1 font-semibold">{{ $statistics['response_tracked'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">已解决</dt>
                <dd class="mt-1 font-semibold">{{ $statistics['resolved_tracked'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">首响超时</dt>
                <dd class="mt-1 font-semibold text-red-700">{{ $statistics['response_breaches'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-slate-500">解决超时</dt>
                <dd class="mt-1 font-semibold text-red-700">{{ $statistics['resolution_breaches'] }}</dd>
            </div>
        </dl>
    </div>
@endsection
