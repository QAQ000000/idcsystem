@extends('theme::layouts.app')

@section('title', __('messages.auth.login'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('messages.auth.login_title') }}</h1>
    <form method="post" action="{{ route('client.login.store') }}" class="max-w-md rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-4 block text-sm">{{ __('messages.auth.email') }}
            <input class="mt-1 w-full rounded border px-3 py-2" name="email" type="email" value="{{ old('email') }}" required>
        </label>
        <label class="mb-4 block text-sm">{{ __('messages.auth.password') }}
            <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" required>
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
        @error('email') <p class="mb-4 text-sm text-red-600">{{ $message }}</p> @enderror
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">{{ __('messages.auth.login') }}</button>
    </form>
    <div class="mt-4 max-w-md">
        <a href="{{ route('oauth.wechat.redirect') }}" class="inline-flex w-full items-center justify-center rounded border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700">
            {{ __('messages.auth.wechat_login') }}
        </a>
    </div>
@endsection
