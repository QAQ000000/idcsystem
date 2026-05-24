@extends('layouts.client')

@section('title', '账单详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $invoice->invoice_number }}</h1>
    <div class="rounded bg-white p-5 shadow-sm">
        <p>状态：{{ $invoice->status }}</p>
        <p>总额：{{ $invoice->total }}</p>

        <div class="mt-5 divide-y">
            @foreach ($invoice->items as $item)
                <div class="py-3 text-sm">
                    <div class="font-medium">{{ $item->description }}</div>
                    <div class="text-zinc-500">{{ $item->amount }}</div>
                </div>
            @endforeach
        </div>

        @if ($errors->any())
            <div class="mt-5 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        @if ($invoice->status !== 'Paid')
            <form method="post" action="{{ route('client.invoices.pay', $invoice) }}" class="mt-5 flex flex-wrap items-end gap-3" onsubmit="const button = this.querySelector('[data-pay-submit]'); if (button) { button.disabled = true; button.textContent = '处理中'; }">
                @csrf
                <label class="block text-sm">
                    支付方式
                    <select class="mt-1 rounded border px-3 py-2" name="payment_method" required>
                        @forelse ($gateways as $gateway)
                            <option value="{{ $gateway->name }}">{{ $gateway->title }}</option>
                        @empty
                            <option value="">暂无可用支付方式</option>
                        @endforelse
                    </select>
                </label>
                <button data-pay-submit class="rounded bg-zinc-900 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-60" @disabled($gateways->isEmpty())>发起支付</button>
            </form>
        @endif
    </div>
@endsection
