@extends('theme::layouts.app')

@section('title', __('messages.profile.title'))

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('messages.profile.title') }}</h1>
        <a class="rounded bg-emerald-700 px-4 py-2 text-sm text-white" href="{{ route('client.account.recharge') }}">{{ __('messages.profile.recharge') }}</a>
    </div>

    <div class="mb-6 rounded bg-white p-5 shadow-sm">
        <div class="text-sm text-zinc-500">{{ __('messages.profile.balance') }}</div>
        <div class="mt-1 text-2xl font-semibold">{{ $client->credit }}</div>
    </div>

    <section class="mb-6 rounded bg-white p-6 shadow-sm">
        <h2 class="font-semibold">{{ __('messages.profile.quota') }}</h2>
        <div class="mt-3 grid gap-4 text-sm md:grid-cols-3">
            <div>{{ __('messages.profile.credit') }}：{{ $client->credit }}</div>
            <div>{{ __('messages.profile.credit_limit') }}：{{ $client->credit_limit }}</div>
            <div>{{ __('messages.profile.available_credit') }}：{{ number_format($client->availableCredit(), 2, '.', '') }}</div>
        </div>
        @if ((float) $client->credit < 0)
            <div class="mt-3 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ __('messages.profile.debt', ['amount' => number_format(abs((float) $client->credit), 2, '.', '')]) }}
            </div>
        @endif
    </section>

    <form method="post" action="{{ route('client.account.profile.update') }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm">
                {{ __('messages.profile.username') }}
                <input class="mt-1 w-full rounded border bg-zinc-100 px-3 py-2" value="{{ $client->username }}" disabled>
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.email') }}
                <input class="mt-1 w-full rounded border bg-zinc-100 px-3 py-2" value="{{ $client->email }}" disabled>
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.currency') }}
                <select class="mt-1 w-full rounded border px-3 py-2" name="currency_id" required>
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency->id }}" @selected((int) old('currency_id', $client->currency_id) === (int) $currency->id)>{{ $currency->code }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.locale') }}
                <select class="mt-1 w-full rounded border px-3 py-2" name="locale" required>
                    @foreach (config('app.available_locales', ['zh_CN', 'en']) as $locale)
                        <option value="{{ $locale }}" @selected(old('locale', $client->locale ?: app()->getLocale()) === $locale)>{{ __('messages.language.' . $locale) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.company_name') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="company_name" value="{{ old('company_name', $client->company_name) }}">
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.phone_code') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="phone_code" value="{{ old('phone_code', $client->phone_code) }}">
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.phone') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="phone" value="{{ old('phone', $client->phone) }}">
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.country') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="country" value="{{ old('country', $client->country) }}">
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.province') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="province" value="{{ old('province', $client->province) }}">
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.country_code') }}
                <input class="mt-1 w-full rounded border px-3 py-2 uppercase" name="country_code" maxlength="2" value="{{ old('country_code', $client->country_code) }}" placeholder="CN">
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.state_code') }}
                <input class="mt-1 w-full rounded border px-3 py-2 uppercase" name="state_code" maxlength="10" value="{{ old('state_code', $client->state_code) }}" placeholder="GD">
            </label>
            <label class="block text-sm">
                {{ __('messages.profile.city') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="city" value="{{ old('city', $client->city) }}">
            </label>
            <label class="block text-sm md:col-span-2">
                {{ __('messages.profile.address') }}
                <input class="mt-1 w-full rounded border px-3 py-2" name="address" value="{{ old('address', $client->address) }}">
            </label>
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="mt-6 rounded bg-zinc-900 px-4 py-2 text-white">{{ __('messages.profile.save') }}</button>
    </form>
@endsection
