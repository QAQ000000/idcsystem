@extends('theme::layouts.app')

@section('title', __('messages.products.detail_title'))

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $product->name }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p class="text-sm text-zinc-500">{{ $product->group?->name }} / {{ $product->type }}</p>
            <p class="mt-4 whitespace-pre-line">{{ $product->description }}</p>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                @foreach ($prices as $cycle => $price)
                    <div class="rounded border p-4">
                        <div class="text-sm text-zinc-500">{{ __('messages.products.' . $cycle) }}</div>
                        <div class="mt-1 text-xl font-semibold">{{ $price['formatted'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <form method="post" action="{{ route('client.cart.add') }}" class="rounded bg-white p-5 shadow-sm">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">

            <label class="mb-4 block text-sm">
                {{ __('messages.products.billing_cycle') }}
                <select class="mt-1 w-full rounded border px-3 py-2" name="billing_cycle">
                    <option value="monthly">{{ __('messages.products.monthly') }}</option>
                    <option value="quarterly">{{ __('messages.products.quarterly') }}</option>
                    <option value="semiannually">{{ __('messages.products.semiannually') }}</option>
                    <option value="annually">{{ __('messages.products.annually') }}</option>
                </select>
            </label>

            <label class="mb-4 block text-sm">
                {{ __('messages.products.quantity') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="qty" type="number" min="1" value="1">
            </label>

            @foreach ($product->customFields as $field)
                <label class="mb-4 block text-sm">
                    {{ $field->field_name }} @if ($field->required)<span class="text-red-600">*</span>@endif
                    @if (in_array($field->field_type, ['dropdown', 'select'], true))
                        <select class="mt-1 w-full rounded border px-3 py-2" name="custom_fields[{{ $field->id }}]" @required($field->required)>
                            <option value="">{{ __('messages.products.select_placeholder') }}</option>
                            @foreach ($field->optionsList() as $option)
                                <option value="{{ $option }}" @selected(old('custom_fields.' . $field->id) === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    @elseif ($field->field_type === 'textarea')
                        <textarea class="mt-1 w-full rounded border px-3 py-2" name="custom_fields[{{ $field->id }}]" rows="3" @required($field->required)>{{ old('custom_fields.' . $field->id) }}</textarea>
                    @elseif ($field->field_type === 'checkbox')
                        <input type="hidden" name="custom_fields[{{ $field->id }}]" value="0">
                        <label class="mt-2 flex items-center gap-2">
                            <input type="checkbox" name="custom_fields[{{ $field->id }}]" value="1" @checked(old('custom_fields.' . $field->id))>
                            <span>{{ $field->description ?: __('messages.common.yes') }}</span>
                        </label>
                    @else
                        <input class="mt-1 w-full rounded border px-3 py-2" name="custom_fields[{{ $field->id }}]" type="{{ $field->field_type === 'password' ? 'password' : 'text' }}" value="{{ old('custom_fields.' . $field->id) }}" @required($field->required)>
                    @endif
                    @if ($field->description && $field->field_type !== 'checkbox')
                        <span class="mt-1 block text-xs text-zinc-500">{{ $field->description }}</span>
                    @endif
                </label>
            @endforeach

            @if ($product->addons->isNotEmpty())
                <section class="mb-4 rounded border bg-zinc-50 p-4">
                    <h2 class="mb-3 font-semibold">{{ __('messages.products.optional_addons') }}</h2>
                    <div class="space-y-3">
                        @foreach ($product->addons as $addon)
                            <label class="flex items-start gap-3 text-sm">
                                <input class="mt-1" type="checkbox" name="addons[]" value="{{ $addon->id }}">
                                <span>
                                    <span class="font-medium">{{ $addon->name }}</span>
                                    <span class="text-zinc-500"> / {{ $addon->billing_cycle === 'recurring' ? __('messages.products.recurring') : __('messages.products.one_time') }} / {{ number_format((float) $addon->price, 2) }}</span>
                                    @if ($addon->description)
                                        <span class="mt-1 block text-xs text-zinc-500">{{ $addon->description }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @endif

            <button class="w-full rounded bg-zinc-900 px-4 py-2 text-white">{{ __('messages.products.add_to_cart') }}</button>
        </form>
    </div>
@endsection
