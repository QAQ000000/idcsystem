@extends('layouts.client')

@section('title', '申请发票')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">申请发票</h1>

    <form method="post" action="{{ route('client.invoices.receipt.store', $invoice) }}" class="rounded bg-white p-6 shadow-sm">
        @csrf

        <div class="mb-5 rounded border border-zinc-200 bg-zinc-50 p-4 text-sm">
            <div>账单：{{ $invoice->invoice_number }}</div>
            <div>金额：{{ $invoice->total }}</div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm">
                发票类型
                <select class="mt-1 w-full rounded border px-3 py-2" name="type" required>
                    <option value="plain" @selected(old('type') === 'plain')>普通发票</option>
                    <option value="vat" @selected(old('type') === 'vat')>增值税专用发票</option>
                </select>
            </label>
            <label class="block text-sm">
                接收邮箱
                <input class="mt-1 w-full rounded border px-3 py-2" name="email" type="email" value="{{ old('email', $client->email) }}" required>
            </label>
            <label class="block text-sm">
                发票抬头
                <input class="mt-1 w-full rounded border px-3 py-2" name="title" value="{{ old('title', $client->company_name ?: $client->username) }}" required>
            </label>
            <label class="block text-sm">
                税号
                <input class="mt-1 w-full rounded border px-3 py-2" name="tax_number" value="{{ old('tax_number') }}">
            </label>
            <label class="block text-sm">
                开户行
                <input class="mt-1 w-full rounded border px-3 py-2" name="bank_name" value="{{ old('bank_name') }}">
            </label>
            <label class="block text-sm">
                银行账号
                <input class="mt-1 w-full rounded border px-3 py-2" name="bank_account" value="{{ old('bank_account') }}">
            </label>
            <label class="block text-sm">
                公司地址
                <input class="mt-1 w-full rounded border px-3 py-2" name="company_address" value="{{ old('company_address', $client->address) }}">
            </label>
            <label class="block text-sm">
                公司电话
                <input class="mt-1 w-full rounded border px-3 py-2" name="company_phone" value="{{ old('company_phone', $client->phone) }}">
            </label>
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="mt-6 rounded bg-zinc-900 px-4 py-2 text-white">提交申请</button>
    </form>
@endsection
