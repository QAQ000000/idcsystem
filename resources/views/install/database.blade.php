@extends('install.layout')

@section('title', '数据库配置')

@section('content')
    <form method="post" action="{{ route('install.database.save') }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        @unless ($checksPassed)
            <div class="mb-5 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">环境检查存在失败项，建议修复后继续。</div>
        @endunless

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm">
                主机
                <input class="mt-1 w-full rounded border px-3 py-2" name="host" value="{{ old('host', env('DB_HOST', '127.0.0.1')) }}" required>
            </label>
            <label class="block text-sm">
                端口
                <input class="mt-1 w-full rounded border px-3 py-2" name="port" type="number" value="{{ old('port', env('DB_PORT', 3306)) }}" required>
            </label>
            <label class="block text-sm">
                数据库
                <input class="mt-1 w-full rounded border px-3 py-2" name="database" value="{{ old('database', env('DB_DATABASE', 'idcsystem')) }}" required>
            </label>
            <label class="block text-sm">
                用户名
                <input class="mt-1 w-full rounded border px-3 py-2" name="username" value="{{ old('username', env('DB_USERNAME', 'idcuser')) }}" required>
            </label>
            <label class="block text-sm md:col-span-2">
                密码
                <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" value="{{ old('password', env('DB_PASSWORD', '')) }}">
            </label>
        </div>

        <div class="mt-6 flex justify-between">
            <a class="rounded border px-4 py-2" href="{{ route('install.index') }}">返回检查</a>
            <button class="rounded bg-slate-900 px-4 py-2 text-white">测试连接并继续安装</button>
        </div>
    </form>
@endsection
