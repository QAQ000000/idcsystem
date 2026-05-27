@extends('layouts.admin')

@section('title', '新建客户分群')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">新建客户分群</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.client-segments.index') }}">返回</a>
    </div>

    <form method="post" action="{{ route('admin.client-segments.store') }}" class="grid gap-5 rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="text-sm">
            名称
            <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name') }}" required maxlength="120">
        </label>
        <label class="text-sm">
            描述
            <textarea class="mt-1 w-full rounded border px-3 py-2" name="description" rows="2">{{ old('description') }}</textarea>
        </label>
        <label class="text-sm">
            类型
            <select class="mt-1 w-full rounded border px-3 py-2" name="type">
                <option value="static" @selected(old('type') === 'static')>静态分群</option>
                <option value="dynamic" @selected(old('type') === 'dynamic')>动态分群</option>
            </select>
        </label>
        <label class="text-sm">
            动态规则 JSON
            <textarea class="mt-1 h-48 w-full rounded border px-3 py-2 font-mono text-xs" name="rules">{{ old('rules', "[\n  {\"field\":\"credit_balance\",\"operator\":\">=\",\"value\":100}\n]") }}</textarea>
            <span class="text-xs text-slate-500">支持 credit_balance、registered_days、total_spent、order_count、active_hosts_count、has_tag。</span>
            @error('rules')<span class="block text-red-700">{{ $message }}</span>@enderror
        </label>
        <button class="w-fit rounded bg-slate-900 px-4 py-2 text-white">创建分群</button>
    </form>
@endsection
