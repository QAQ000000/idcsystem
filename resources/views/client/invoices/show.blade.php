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

        @if ($invoice->status !== 'Paid')
            <form method="post" action="{{ route('client.invoices.pay', $invoice) }}" class="mt-5 flex items-end gap-3">
                @csrf
                <label class="block text-sm">
                    支付方式
                    <select class="mt-1 rounded border px-3 py-2" name="payment_method">
                        <option value="manual">线下转账</option>
                        <option value="balance">余额支付</option>
                    </select>
                </label>
                <button class="rounded bg-zinc-900 px-4 py-2 text-white">标记支付</button>
            </form>
        @endif
    </div>
@endsection
