@extends('layouts.admin')

@section('title', '产品价格')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">价格配置：{{ $product->name }}</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.products.show', $product) }}">返回产品</a>
    </div>

    <form method="post" action="{{ route('admin.products.pricing.update', $product) }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-5 block text-sm">
            货币
            <select class="mt-1 w-full rounded border px-3 py-2 md:w-64" name="currency_id">
                @foreach ($currencies as $currency)
                    <option value="{{ $currency->id }}" @selected((int) old('currency_id', $selectedCurrencyId ?? $pricing?->currency_id ?? $currencies->first()?->id) === (int) $currency->id)>
                        {{ $currency->code }}
                    </option>
                @endforeach
            </select>
        </label>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach ([
                'monthly' => '月付',
                'quarterly' => '季付',
                'semiannually' => '半年付',
                'annually' => '年付',
                'biennially' => '两年付',
                'triennially' => '三年付',
                'onetime' => '一次性',
                'hourly' => '小时',
                'daily' => '天',
            ] as $field => $label)
                <label class="block text-sm">
                    {{ $label }}
                    <input class="mt-1 w-full rounded border px-3 py-2" name="{{ $field }}" type="number" step="0.01" min="-1" value="{{ old($field, $pricing?->{$field} ?? -1) }}">
                </label>
            @endforeach
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-3">
            @foreach ([
                'monthly_setup' => '月付安装费',
                'quarterly_setup' => '季付安装费',
                'semiannually_setup' => '半年付安装费',
                'annually_setup' => '年付安装费',
                'biennially_setup' => '两年付安装费',
                'triennially_setup' => '三年付安装费',
            ] as $field => $label)
                <label class="block text-sm">
                    {{ $label }}
                    <input class="mt-1 w-full rounded border px-3 py-2" name="{{ $field }}" type="number" step="0.01" min="0" value="{{ old($field, $pricing?->{$field} ?? 0) }}">
                </label>
            @endforeach
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="mt-6 rounded bg-slate-900 px-4 py-2 text-white">保存价格</button>
    </form>
@endsection
