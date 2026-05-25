@extends('install.layout')

@section('title', '数据库配置')

@section('content')
    @if ($storedDatabaseReady ?? false)
        <div class="mb-5 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            数据库已经完成初始化，可以直接进入管理员配置；如需更换数据库，请重新填写并提交下方配置。
        </div>
    @endif

    <form method="post" action="{{ route('install.database.save') }}" class="rounded bg-white p-6 shadow-sm" data-prevent-double-submit>
        @csrf
        @unless ($checksPassed)
            <div class="mb-5 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">环境检查存在失败项，建议修复后继续。</div>
        @endunless

        @php($storedDatabase = $storedDatabase ?? [])

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm">
                主机
                <input class="mt-1 w-full rounded border px-3 py-2" name="host" value="{{ old('host', $storedDatabase['host'] ?? env('DB_HOST', '127.0.0.1')) }}" required>
            </label>
            <label class="block text-sm">
                端口
                <input class="mt-1 w-full rounded border px-3 py-2" name="port" type="number" value="{{ old('port', $storedDatabase['port'] ?? env('DB_PORT', 3306)) }}" required>
            </label>
            <label class="block text-sm">
                数据库
                <input class="mt-1 w-full rounded border px-3 py-2" name="database" value="{{ old('database', $storedDatabase['database'] ?? env('DB_DATABASE', 'idcsystem')) }}" required>
            </label>
            <label class="block text-sm">
                用户名
                <input class="mt-1 w-full rounded border px-3 py-2" name="username" value="{{ old('username', $storedDatabase['username'] ?? env('DB_USERNAME', 'idcuser')) }}" required>
            </label>
            <label class="block text-sm md:col-span-2">
                密码
                <input class="mt-1 w-full rounded border px-3 py-2" name="password" type="password" value="{{ old('password', '') }}" placeholder="{{ !empty($storedDatabase['password'] ?? '') ? '已保存，留空则使用已保存密码' : '' }}" autocomplete="new-password">
            </label>
        </div>

        <div class="mt-6 flex justify-between">
            <a class="rounded border px-4 py-2" href="{{ route('install.index') }}">返回检查</a>
            <div class="flex gap-3">
                @if ($storedDatabaseReady ?? false)
                    <a class="rounded border px-4 py-2" href="{{ route('install.admin') }}">继续配置管理员</a>
                @endif
                <button data-submit-button data-loading-text="正在初始化..." class="rounded bg-slate-900 px-4 py-2 text-white disabled:cursor-not-allowed disabled:opacity-70">测试连接并继续安装</button>
            </div>
        </div>
    </form>
@endsection
