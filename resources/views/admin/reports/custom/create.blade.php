@extends('layouts.admin')

@section('title', '新建自定义报表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">新建自定义报表</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.reports.custom.index') }}">返回</a>
    </div>

    <form method="post" action="{{ route('admin.reports.custom.store') }}" class="grid gap-5 rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="text-sm">
            名称
            <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name') }}" required maxlength="120">
            @error('name')<span class="text-red-700">{{ $message }}</span>@enderror
        </label>

        <label class="text-sm">
            描述
            <textarea class="mt-1 w-full rounded border px-3 py-2" name="description" rows="2">{{ old('description') }}</textarea>
        </label>

        <label class="text-sm">
            SQL
            <textarea class="mt-1 w-full rounded border font-mono text-xs px-3 py-2" name="query" rows="8" required>{{ old('query', 'select id, username, email, created_at from clients') }}</textarea>
            @error('query')<span class="text-red-700">{{ $message }}</span>@enderror
        </label>

        <label class="text-sm">
            列顺序
            <input class="mt-1 w-full rounded border px-3 py-2" name="columns" value="{{ old('columns') }}" placeholder="id, username, email">
        </label>

        <label class="text-sm">
            定时计划
            <select class="mt-1 w-full rounded border px-3 py-2" name="schedule">
                <option value="">手动执行</option>
                <option value="every_minute">每分钟</option>
                <option value="hourly">每小时</option>
                <option value="daily">每天</option>
            </select>
        </label>

        <label class="text-sm">
            接收人
            <input class="mt-1 w-full rounded border px-3 py-2" name="recipients" value="{{ old('recipients') }}" placeholder="ops@example.com, finance@example.com">
        </label>

        <button class="w-fit rounded bg-slate-900 px-4 py-2 text-white">创建并执行</button>
    </form>
@endsection
