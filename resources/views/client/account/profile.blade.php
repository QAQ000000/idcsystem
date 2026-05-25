@extends('layouts.client')

@section('title', '账户资料')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">账户资料</h1>
        <a class="rounded bg-emerald-700 px-4 py-2 text-sm text-white" href="{{ route('client.account.recharge') }}">账户充值</a>
    </div>

    <div class="mb-6 rounded bg-white p-5 shadow-sm">
        <div class="text-sm text-zinc-500">当前余额</div>
        <div class="mt-1 text-2xl font-semibold">{{ $client->credit }}</div>
    </div>

    <form method="post" action="{{ route('client.account.profile.update') }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm">
                用户名
                <input class="mt-1 w-full rounded border bg-zinc-100 px-3 py-2" value="{{ $client->username }}" disabled>
            </label>
            <label class="block text-sm">
                邮箱
                <input class="mt-1 w-full rounded border bg-zinc-100 px-3 py-2" value="{{ $client->email }}" disabled>
            </label>
            <label class="block text-sm">
                公司名称
                <input class="mt-1 w-full rounded border px-3 py-2" name="company_name" value="{{ old('company_name', $client->company_name) }}">
            </label>
            <label class="block text-sm">
                手机区号
                <input class="mt-1 w-full rounded border px-3 py-2" name="phone_code" value="{{ old('phone_code', $client->phone_code) }}">
            </label>
            <label class="block text-sm">
                手机号
                <input class="mt-1 w-full rounded border px-3 py-2" name="phone" value="{{ old('phone', $client->phone) }}">
            </label>
            <label class="block text-sm">
                国家
                <input class="mt-1 w-full rounded border px-3 py-2" name="country" value="{{ old('country', $client->country) }}">
            </label>
            <label class="block text-sm">
                省份
                <input class="mt-1 w-full rounded border px-3 py-2" name="province" value="{{ old('province', $client->province) }}">
            </label>
            <label class="block text-sm">
                城市
                <input class="mt-1 w-full rounded border px-3 py-2" name="city" value="{{ old('city', $client->city) }}">
            </label>
            <label class="block text-sm md:col-span-2">
                地址
                <input class="mt-1 w-full rounded border px-3 py-2" name="address" value="{{ old('address', $client->address) }}">
            </label>
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="mt-6 rounded bg-zinc-900 px-4 py-2 text-white">保存资料</button>
    </form>
@endsection
