@extends('theme::layouts.app')

@section('title', '账户安全')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">账户安全</h1>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded bg-white p-6 shadow-sm lg:col-span-2">
            <h2 class="mb-4 font-semibold">修改密码</h2>
            <form method="post" action="{{ route('client.account.password.update') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <label class="block text-sm">
                    当前密码
                    <input class="mt-1 w-full rounded border px-3 py-2" name="current_password" type="password" required>
                </label>
                <label class="block text-sm">
                    新密码
                    <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" required>
                </label>
                <label class="block text-sm">
                    确认新密码
                    <input class="mt-1 w-full rounded border px-3 py-2" name="password_confirmation" type="password" required>
                </label>
                @if ($errors->any())
                    <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif
                <button class="rounded bg-zinc-900 px-4 py-2 text-white">修改密码</button>
            </form>
        </section>

        <aside class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">安全状态</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500">最近登录时间</dt>
                    <dd>{{ userTime($client->last_login_at) ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">最近登录 IP</dt>
                    <dd>{{ $client->last_login_ip ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">两步验证</dt>
                    <dd>{{ $client->two_factor_enabled ? '已开启' : '未开启' }}</dd>
                </div>
            </dl>
        </aside>
    </div>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">两步验证</h2>
        @if ($client->two_factor_enabled)
            <p class="text-sm text-zinc-600">当前已开启 TOTP 两步验证。</p>
            <form method="post" action="{{ route('client.account.2fa.disable') }}" class="mt-4 max-w-md space-y-4">
                @csrf
                <label class="block text-sm">
                    当前密码
                    <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" required>
                </label>
                @error('password') <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror
                <button class="rounded border border-red-300 px-4 py-2 text-red-700">关闭两步验证</button>
            </form>
        @else
            <div class="grid gap-5 lg:grid-cols-[2fr_1fr]">
                <div>
                    <p class="text-sm text-zinc-600">使用认证器应用添加下面的密钥，或扫描兼容二维码链接。</p>
                    <div class="mt-4 rounded border bg-zinc-50 p-4 font-mono text-sm break-all">{{ $twoFactorSecret }}</div>
                    <a class="mt-3 inline-block text-sm text-blue-700" href="{{ $twoFactorQrCodeUrl }}">打开认证器二维码链接</a>
                </div>
                <form method="post" action="{{ route('client.account.2fa.enable') }}" class="space-y-4">
                    @csrf
                    <label class="block text-sm">
                        验证码
                        <input class="mt-1 w-full rounded border px-3 py-2" name="code" inputmode="numeric" pattern="\d{6}" required>
                    </label>
                    @error('code') <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror
                    <button class="rounded bg-zinc-900 px-4 py-2 text-white">启用两步验证</button>
                </form>
            </div>
        @endif
    </section>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">登录记录</h2>
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">IP</th>
                    <th class="px-4 py-3">设备</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($loginLogs as $log)
                    <tr>
                        <td class="px-4 py-3">{{ userTime($log->logged_in_at) }}</td>
                        <td class="px-4 py-3">{{ $log->ip ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $log->user_agent ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-zinc-500" colspan="3">暂无登录记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
@endsection
