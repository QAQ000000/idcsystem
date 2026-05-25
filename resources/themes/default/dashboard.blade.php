@extends('theme::layouts.app')

@section('title', '控制台')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">控制台</h1>
    @if (($announcements ?? collect())->isNotEmpty())
        <div class="mb-6 space-y-3">
            @foreach ($announcements as $announcement)
                <div class="rounded border-l-4 p-4 {{ $announcement->type === 'maintenance' ? 'border-yellow-400 bg-yellow-50' : ($announcement->type === 'warning' ? 'border-red-400 bg-red-50' : 'border-blue-400 bg-blue-50') }}">
                    <div class="font-semibold">{{ $announcement->title }}</div>
                    <div class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $announcement->content }}</div>
                </div>
            @endforeach
        </div>
    @endif

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
