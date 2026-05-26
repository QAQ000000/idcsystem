@extends('theme::layouts.app')

@section('title', __('messages.auth.register'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('messages.auth.register_title') }}</h1>
    <form method="post" action="{{ route('client.register.store') }}" class="max-w-md rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-4 block text-sm">{{ __('messages.auth.username') }}
            <input class="mt-1 w-full rounded border px-3 py-2" name="username" value="{{ old('username') }}" required>
        </label>
        <label class="mb-4 block text-sm">{{ __('messages.auth.email') }}
            <input class="mt-1 w-full rounded border px-3 py-2" name="email" type="email" value="{{ old('email') }}" required>
        </label>
        <label class="mb-4 block text-sm">{{ __('messages.auth.password') }}
            <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" required>
        </label>
        <label class="mb-4 block text-sm">{{ __('messages.auth.confirm_password') }}
            <input class="mt-1 w-full rounded border px-3 py-2" name="password_confirmation" type="password" required>
        </label>
        @if ($captcha)
            <input type="hidden" name="captcha_key" value="{{ $captcha['key'] }}">
            <label class="mb-4 block text-sm">{{ __('messages.auth.captcha') }}
                <span class="mt-1 flex gap-3">
                    <input class="w-full rounded border px-3 py-2" name="captcha_code" required>
                    <img class="h-11 rounded border" src="{{ $captcha['image_url'] }}" alt="{{ __('messages.auth.captcha') }}">
                </span>
            </label>
            @error('captcha_code') <p class="mb-4 text-sm text-red-600">{{ $message }}</p> @enderror
        @endif
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">{{ __('messages.auth.register') }}</button>
    </form>
@endsection
