@extends('layouts.admin')

@section('title', '账单详情')

@section('content')
    @php($adminUser = auth('admin')->user())
    @php($canAdmin = fn (string $permission): bool => $adminUser && ($adminUser->hasRole('super-admin') || $adminUser->can($permission)))
    @php($clientDeleted = $invoice->client?->trashed() ?? false)

    <h1 class="mb-4 text-2xl font-semibold">{{ $invoice->invoice_number }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p>
                客户：{{ $invoice->client?->username }}
                @if ($clientDeleted)
                    <span class="ml-1 inline-flex rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-700">已删除</span>
                @endif
            </p>
            <p>状态：{{ $invoice->status }}</p>
            <p>总额：{{ $invoice->total }}</p>
            @if (in_array($invoice->status, ['Paid', 'Partially Refunded'], true))
                <p>剩余可退：{{ number_format($remainingRefundableAmount, 2, '.', '') }}</p>
            @endif

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
            @if ($clientDeleted && $invoice->status === 'Unpaid')
                <p class="mb-4 text-sm text-slate-500">客户已删除，不能标记支付该账单。</p>
            @endif

            @if (!$clientDeleted && $invoice->status === 'Unpaid' && $canAdmin('invoice.manage'))
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
            @endif

            @if (in_array($invoice->status, ['Paid', 'Partially Refunded'], true) && $canAdmin('invoice.refund'))
                <form method="post" action="{{ route('admin.invoices.refund', $invoice) }}" class="mt-5 space-y-3">
                    @csrf
                    <label class="block text-sm">
                        退款金额
                        <input class="mt-1 w-full rounded border px-3 py-2" name="amount" type="number" step="0.01" min="0.01" max="{{ number_format($remainingRefundableAmount, 2, '.', '') }}" value="{{ number_format($remainingRefundableAmount, 2, '.', '') }}">
                    </label>
                    <button class="w-full rounded border border-red-300 px-4 py-2 text-red-700">记录退款</button>
                </form>
            @endif

            @if (
                !in_array($invoice->status, ['Unpaid', 'Paid', 'Partially Refunded'], true)
                || ($invoice->status === 'Unpaid' && !$clientDeleted && !$canAdmin('invoice.manage'))
                || ($invoice->status === 'Unpaid' && $clientDeleted)
                || (in_array($invoice->status, ['Paid', 'Partially Refunded'], true) && !$canAdmin('invoice.refund'))
            )
                <p class="text-sm text-slate-500">当前没有可执行的账单操作。</p>
            @endif
        </div>
    </div>
@endsection
