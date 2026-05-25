@extends('layouts.admin')

@section('title', '管理员两步验证')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">管理员两步验证</h1>
        <p class="mt-1 text-sm text-slate-500">为当前管理员账号启用或关闭 TOTP 验证。</p>
    </div>

    <section class="rounded bg-white p-6 shadow-sm">
        <div class="text-sm text-slate-600">当前状态：{{ $admin->two_factor_enabled ? '已启用' : '未启用' }}</div>

        @if ($admin->two_factor_enabled)
            <form method="post" action="{{ route('admin.profile.2fa.disable') }}" class="mt-6 max-w-md">
                @csrf
                <label class="block text-sm">
                    当前密码
                    <input class="mt-1 w-full rounded border px-3 py-2" type="password" name="password" required>
                </label>
                <button class="mt-4 rounded bg-red-700 px-4 py-2 text-white">关闭两步验证</button>
            </form>
        @else
            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <div>
                    <div class="text-sm font-medium">扫码绑定</div>
                    <div class="mt-2 break-all rounded border bg-slate-50 p-3 text-xs">{{ $qrCodeUrl }}</div>
                    <div class="mt-3 text-sm text-slate-600">手动密钥：{{ $secret }}</div>
                </div>
                <form method="post" action="{{ route('admin.profile.2fa.enable') }}">
                    @csrf
                    <label class="block text-sm">
                        6 位验证码
                        <input class="mt-1 w-full rounded border px-3 py-2" name="code" inputmode="numeric" pattern="\d{6}" required>
                    </label>
                    <button class="mt-4 rounded bg-slate-900 px-4 py-2 text-white">启用两步验证</button>
                </form>
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif
    </section>
@endsection
