@extends('layouts.client')

@section('title', '产品详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $product->name }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">{{ $product->group?->name }} / {{ $product->type }}</p>
            <p class="mt-4 whitespace-pre-line">{{ $product->description }}</p>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                @foreach ($prices as $cycle => $amount)
                    <div class="rounded border p-4">
                        <div class="text-sm text-zinc-500">{{ ['monthly' => '月付', 'quarterly' => '季付', 'semiannually' => '半年付', 'annually' => '年付'][$cycle] }}</div>
                        <div class="mt-1 text-xl font-semibold">{{ $currency?->prefix }}{{ number_format($amount, 2) }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <form method="post" action="{{ route('client.cart.add') }}" class="rounded bg-white p-5 shadow-sm">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">

            <label class="mb-4 block text-sm">
                计费周期
                <select class="mt-1 w-full rounded border px-3 py-2" name="billing_cycle">
                    <option value="monthly">月付</option>
                    <option value="quarterly">季付</option>
                    <option value="semiannually">半年付</option>
                    <option value="annually">年付</option>
                </select>
            </label>

            <label class="mb-4 block text-sm">
                数量
                <input class="mt-1 w-full rounded border px-3 py-2" name="qty" type="number" min="1" value="1">
            </label>

            <button class="w-full rounded bg-zinc-900 px-4 py-2 text-white">加入购物车</button>
        </form>
    </div>
@endsection
