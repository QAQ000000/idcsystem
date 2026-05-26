@extends('theme::layouts.app')

@section('title', __('messages.cart.title'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('messages.cart.title') }}</h1>
    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-600">
                <tr>
                    <th class="px-4 py-3">{{ __('messages.cart.product') }}</th>
                    <th class="px-4 py-3">{{ __('messages.cart.cycle') }}</th>
                    <th class="px-4 py-3">{{ __('messages.cart.quantity') }}</th>
                    <th class="px-4 py-3">{{ __('messages.cart.unit_price') }}</th>
                    <th class="px-4 py-3">{{ __('messages.common.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($cart['items'] ?? [] as $item)
                    <tr>
                        <td class="px-4 py-3">
                            <div>{{ $item['product_name'] }}</div>
                            @if (!empty($item['custom_field_labels']))
                                <dl class="mt-2 space-y-1 text-xs text-zinc-500">
                                    @foreach ($item['custom_field_labels'] as $label => $value)
                                        <div><dt class="inline">{{ $label }}：</dt><dd class="inline">{{ $value }}</dd></div>
                                    @endforeach
                                </dl>
                            @endif
                            @if (!empty($item['addons']))
                                <div class="mt-2 text-xs text-zinc-500">
                                    {{ __('messages.cart.addons') }}：
                                    {{ collect($item['addons'])->map(fn ($addon) => $addon['name'] . ' +' . number_format((float) $addon['price'], 2))->join('、') }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $item['billing_cycle'] }}</td>
                        <td class="px-4 py-3">{{ $item['qty'] }}</td>
                        <td class="px-4 py-3">{{ $currencies->format((float) $item['price'] + (float) ($item['addon_total'] ?? 0), $currency) }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('client.cart.remove', $item['id']) }}">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-600">{{ __('messages.cart.remove') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-zinc-500" colspan="5">{{ __('messages.cart.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[2fr_1fr]">
        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-semibold">{{ __('messages.cart.promo_code') }}</h2>
            @if ($errors->any())
                <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif

            @if (isset($cart['promo']))
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span>{{ __('messages.cart.applied') }}：{{ $cart['promo']['code'] }}</span>
                    <form method="post" action="{{ route('client.cart.promo.remove') }}">
                        @csrf
                        @method('DELETE')
                        <button class="text-red-600">{{ __('messages.cart.remove') }}</button>
                    </form>
                </div>
            @else
                <form method="post" action="{{ route('client.cart.promo') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block text-sm">
                        {{ __('messages.cart.code') }}
                        <input class="mt-1 rounded border px-3 py-2" name="code" value="{{ old('code') }}" maxlength="100" required>
                    </label>
                    <button class="rounded bg-zinc-900 px-4 py-2 text-white" @disabled(count($cart['items'] ?? []) === 0)>{{ __('messages.cart.apply_promo') }}</button>
                </form>
            @endif
        </div>

        <div class="rounded bg-white p-5 text-sm shadow-sm">
            <div class="flex justify-between py-1">
                <span class="text-zinc-500">{{ __('messages.cart.subtotal') }}</span>
                <span>{{ $currencies->format((float) ($cart['totals']['subtotal'] ?? 0), $currency) }}</span>
            </div>
            <div class="flex justify-between py-1">
                <span class="text-zinc-500">{{ __('messages.cart.discount') }}</span>
                <span>-{{ $currencies->format((float) ($cart['totals']['discount'] ?? 0), $currency) }}</span>
            </div>
            <div class="mt-2 flex justify-between border-t pt-3 font-semibold">
                <span>{{ __('messages.cart.total') }}</span>
                <span>{{ $currencies->format((float) ($cart['totals']['total'] ?? 0), $currency) }}</span>
            </div>
        </div>
    </div>

    <form method="post" action="{{ route('client.cart.checkout') }}" class="mt-4">
        @csrf
        <button class="rounded bg-zinc-900 px-4 py-2 text-white" @disabled(count($cart['items'] ?? []) === 0)>{{ __('messages.cart.checkout') }}</button>
    </form>
@endsection
