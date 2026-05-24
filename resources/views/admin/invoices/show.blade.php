@extends('layouts.admin')

@section('title', '账单详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $invoice->invoice_number }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p>客户：{{ $invoice->client?->username }}</p>
            <p>状态：{{ $invoice->status }}</p>
            <p>总额：{{ $invoice->total }}</p>

            <div class="mt-5 divide-y">
                @foreach ($invoice->items as $item)
                    <div class="py-3 text-sm">
                        <div class="font-medium">{{ $item->description }}</div>
                        <div class="text-slate-500">{{ $item->amount }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">账单操作</h2>
            <form method="post" action="{{ route('admin.invoices.mark-paid', $invoice) }}" class="space-y-3">
                @csrf
                <label class="block text-sm">
                    支付方式
                    <input class="mt-1 w-full rounded border px-3 py-2" name="payment_method" value="manual">
                </label>
                <label class="block text-sm">
                    交易号
                    <input class="mt-1 w-full rounded border px-3 py-2" name="trans_id" value="ADMIN-{{ $invoice->id }}">
                </label>
                <button class="w-full rounded bg-slate-900 px-4 py-2 text-white">标记已支付</button>
            </form>

            <form method="post" action="{{ route('admin.invoices.refund', $invoice) }}" class="mt-5 space-y-3">
                @csrf
                <label class="block text-sm">
                    退款金额
                    <input class="mt-1 w-full rounded border px-3 py-2" name="amount" type="number" step="0.01" min="0.01" value="{{ $invoice->total }}">
                </label>
                <button class="w-full rounded border border-red-300 px-4 py-2 text-red-700">记录退款</button>
            </form>
        </div>
    </div>
@endsection
