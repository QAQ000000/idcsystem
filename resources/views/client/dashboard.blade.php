@extends('layouts.client')

@section('title', '控制台')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">控制台</h1>
    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">可用额度</div>
            <div class="mt-2 text-xl font-semibold">{{ number_format($client->availableCredit(), 2, '.', '') }}</div>
            <div class="mt-1 text-xs {{ (float) $client->credit < 0 ? 'text-red-600' : 'text-slate-500' }}">
                @if ((float) $client->credit < 0)
                    当前欠款：{{ number_format(abs((float) $client->credit), 2, '.', '') }}
                @else
                    余额：{{ $client->credit }} / 信用额度：{{ $client->credit_limit }}
                @endif
            </div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">服务：{{ $hosts->count() }}</div>
        <div class="rounded bg-white p-5 shadow-sm">账单：{{ $invoices->count() }}</div>
        <div class="rounded bg-white p-5 shadow-sm">工单：{{ $tickets->count() }}</div>
    </div>
@endsection
