@extends('theme::layouts.app')

@section('title', '账户充值')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">账户充值</h1>

    <div class="grid gap-6 md:grid-cols-[1fr_2fr]">
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-zinc-500">当前余额</div>
            <div class="mt-1 text-2xl font-semibold">{{ $client->credit }}</div>
        </div>

        <form method="post" action="{{ route('client.account.recharge.store') }}" class="rounded bg-white p-6 shadow-sm">
            @csrf

            <label class="block text-sm">
                充值金额
                <input
                    class="mt-1 w-full rounded border px-3 py-2"
                    type="number"
                    name="amount"
                    value="{{ old('amount') }}"
                    min="1"
                    max="99999"
                    step="0.01"
                    required
                >
            </label>

            @if ($errors->any())
                <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif

            <button class="mt-6 rounded bg-zinc-900 px-4 py-2 text-white">生成充值账单</button>
        </form>
    </div>
@endsection
