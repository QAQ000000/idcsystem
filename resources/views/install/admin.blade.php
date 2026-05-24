@extends('install.layout')

@section('title', '管理员配置')

@section('content')
    <form method="post" action="{{ route('install.admin.save') }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm">
                管理员用户名
                <input class="mt-1 w-full rounded border px-3 py-2" name="username" value="{{ old('username', 'admin') }}" required>
            </label>
            <label class="block text-sm">
                邮箱
                <input class="mt-1 w-full rounded border px-3 py-2" name="email" type="email" value="{{ old('email', 'admin@example.com') }}" required>
            </label>
            <label class="block text-sm">
                姓名
                <input class="mt-1 w-full rounded border px-3 py-2" name="real_name" value="{{ old('real_name', '系统管理员') }}">
            </label>
            <div></div>
            <label class="block text-sm">
                密码
                <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" required>
            </label>
            <label class="block text-sm">
                确认密码
                <input class="mt-1 w-full rounded border px-3 py-2" name="password_confirmation" type="password" required>
            </label>
        </div>

        <div class="mt-6 flex justify-between">
            <a class="rounded border px-4 py-2" href="{{ route('install.database') }}">返回数据库配置</a>
            <button class="rounded bg-slate-900 px-4 py-2 text-white">完成安装</button>
        </div>
    </form>
@endsection
