@extends('theme::layouts.app')

@section('title', __('messages.products.title'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('messages.products.title') }}</h1>
    <div class="grid gap-4 md:grid-cols-3">
        @forelse ($products as $product)
            <a href="{{ route('client.products.show', $product) }}" class="rounded bg-white p-5 shadow-sm">
                <h2 class="font-semibold">{{ $product->name }}</h2>
                <p class="mt-2 text-sm text-zinc-600">{{ $product->type }}</p>
                @php($price = $monthlyPrices[$product->id] ?? null)
                <p class="mt-4 text-lg font-semibold">{{ $price['formatted'] ?? '-' }}<span class="ml-1 text-xs font-normal text-zinc-500">{{ __('messages.products.monthly') }}</span></p>
            </a>
        @empty
            <p class="rounded bg-white p-5 shadow-sm">{{ __('messages.products.empty') }}</p>
        @endforelse
    </div>
@endsection
