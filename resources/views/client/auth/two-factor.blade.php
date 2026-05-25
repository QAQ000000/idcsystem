@extends('layouts.client')

@section('title', '两步验证')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">两步验证</h1>
    <form method="post" action="{{ route('client.login.2fa.verify') }}" class="max-w-md rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-4 block text-sm">
            验证码
            <input class="mt-1 w-full rounded border px-3 py-2" name="code" inputmode="numeric" pattern="\d{6}" required>
        </label>
        @error('code') <p class="mb-4 text-sm text-red-600">{{ $message }}</p> @enderror
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">完成登录</button>
    </form>
@endsection
