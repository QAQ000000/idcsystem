@extends('layouts.admin')

@section('title', '新建邮件活动')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">新建邮件活动</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.campaigns.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.campaigns.store') }}" class="space-y-5">
        @csrf
        <div class="grid gap-5 rounded bg-white p-6 shadow-sm md:grid-cols-2">
            <label class="block text-sm">
                活动名称
                <input class="mt-1 w-full rounded border px-3 py-2" name="name" value="{{ old('name') }}" required>
            </label>
            <label class="block text-sm">
                邮件主题
                <input class="mt-1 w-full rounded border px-3 py-2" name="subject" value="{{ old('subject') }}" required>
            </label>
            <div class="md:col-span-2">
                <div class="text-sm font-medium">目标客户分组</div>
                <div class="mt-2 grid gap-2 rounded border p-3 text-sm md:grid-cols-3">
                    @forelse ($groups as $group)
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="target_groups[]" value="{{ $group->id }}" @checked(in_array($group->id, old('target_groups', [])))>
                            <span>{{ $group->name }}</span>
                        </label>
                    @empty
                        <div class="text-slate-500">暂无客户分组；不选择时默认面向全部活跃客户。</div>
                    @endforelse
                </div>
                <p class="mt-1 text-xs text-slate-500">不选择任何分组时，活动会面向全部活跃客户。</p>
            </div>
            <label class="block text-sm md:col-span-2">
                邮件内容
                <textarea class="mt-1 h-72 w-full rounded border px-3 py-2 font-mono text-sm" name="content" required>{{ old('content', '<p>您好 {{client_name}}，</p><p>这里填写邮件内容。</p>') }}</textarea>
            </label>
        </div>

        @if ($errors->any())
            <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <button class="rounded bg-slate-900 px-4 py-2 text-white">保存活动</button>
    </form>
@endsection
