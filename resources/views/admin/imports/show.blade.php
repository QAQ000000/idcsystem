@extends('layouts.admin')

@section('title', '导入任务详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">导入任务 #{{ $importJob->id }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $importJob->type }} / {{ $importJob->status }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.imports.index') }}">返回列表</a>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">总行数</div><div class="mt-2 text-xl font-semibold">{{ $importJob->total_rows }}</div></div>
        <div class="rounded bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">成功</div><div class="mt-2 text-xl font-semibold">{{ $importJob->success_count }}</div></div>
        <div class="rounded bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">失败</div><div class="mt-2 text-xl font-semibold">{{ $importJob->failed_count }}</div></div>
        <div class="rounded bg-white p-4 shadow-sm"><div class="text-sm text-slate-500">完成时间</div><div class="mt-2 text-sm">{{ $importJob->completed_at?->format('Y-m-d H:i:s') ?: '-' }}</div></div>
    </div>

    <section class="rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-lg font-semibold">错误行</h2>
        <div class="space-y-3 text-sm">
            @forelse (($importJob->errors ?? []) as $error)
                <div class="rounded border border-red-100 bg-red-50 p-3 text-red-800">
                    第 {{ $error['line'] ?? '-' }} 行：{{ $error['message'] ?? '未知错误' }}
                </div>
            @empty
                <div class="text-slate-500">暂无错误</div>
            @endforelse
        </div>
    </section>
@endsection
