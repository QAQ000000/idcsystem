@extends('layouts.client')

@section('title', '注册')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">客户注册</h1>
    <form method="post" action="{{ route('client.register.store') }}" class="max-w-md rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-4 block text-sm">用户名
            <input class="mt-1 w-full rounded border px-3 py-2" name="username" value="{{ old('username') }}" required>
        </label>
        <label class="mb-4 block text-sm">邮箱
            <input class="mt-1 w-full rounded border px-3 py-2" name="email" type="email" value="{{ old('email') }}" required>
        </label>
        <label class="mb-4 block text-sm">密码
            <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" required>
        </label>
        <label class="mb-4 block text-sm">确认密码
            <input class="mt-1 w-full rounded border px-3 py-2" name="password_confirmation" type="password" required>
        </label>
        @if ($captcha)
            <input type="hidden" name="captcha_key" value="{{ $captcha['key'] }}">
            <label class="mb-4 block text-sm">验证码
                <span class="mt-1 flex gap-3">
                    <input class="w-full rounded border px-3 py-2" name="captcha_code" required>
                    <img class="h-11 rounded border" src="{{ $captcha['image_url'] }}" alt="验证码">
                </span>
            </label>
            @error('captcha_code') <p class="mb-4 text-sm text-red-600">{{ $message }}</p> @enderror
        @endif
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">注册</button>
    </form>
@endsection
