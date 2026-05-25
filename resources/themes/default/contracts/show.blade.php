@extends('theme::layouts.app')

@section('title', '合同详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $contract->title }}</h1>
            <p class="mt-1 text-sm text-zinc-500">状态：{{ $contract->status }} @if ($contract->order) / 订单：{{ $contract->order->order_number }} @endif</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.contracts.index') }}">返回列表</a>
    </div>

    <section class="rounded bg-white p-6 shadow-sm">
        <div class="prose max-w-none whitespace-pre-wrap text-sm leading-7">{{ $contract->content }}</div>

        @if ($contract->status === 'pending_signature')
            <form method="post" action="{{ route('client.contracts.sign', $contract) }}" class="mt-6 border-t pt-5" onsubmit="const button = this.querySelector('button'); button.disabled = true; button.textContent = '签署中';">
                @csrf
                <button class="rounded bg-zinc-900 px-4 py-2 text-sm text-white disabled:opacity-60">确认签署</button>
            </form>
        @elseif ($contract->status === 'signed')
            <div class="mt-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                已于 {{ $contract->signed_at?->format('Y-m-d H:i:s') }} 签署，签署 IP：{{ $contract->sign_ip ?: '-' }}
            </div>
        @endif
    </section>
@endsection
