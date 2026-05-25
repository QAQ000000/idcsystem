@extends('layouts.client')

@section('title', '登录')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">客户登录</h1>
    <form method="post" action="{{ route('client.login.store') }}" class="max-w-md rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-4 block text-sm">邮箱
            <input class="mt-1 w-full rounded border px-3 py-2" name="email" type="email" value="{{ old('email') }}" required>
        </label>
        <label class="mb-4 block text-sm">密码
            <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" required>
        </label>
        @error('email') <p class="mb-4 text-sm text-red-600">{{ $message }}</p> @enderror
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">登录</button>
    </form>
    <div class="mt-4 max-w-md">
        <a href="{{ route('oauth.wechat.redirect') }}" class="inline-flex w-full items-center justify-center rounded border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700">
            微信登录
        </a>
    </div>
@endsection
