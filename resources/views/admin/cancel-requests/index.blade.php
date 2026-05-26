@extends('layouts.admin')

@section('title', '取消申请')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">取消申请</h1>
            <p class="mt-1 text-sm text-slate-500">审核客户提交的服务取消申请，并执行立即取消或到期取消。</p>
        </div>
    </div>

    @if (session('error'))
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <form method="get" class="mb-6 flex flex-wrap items-end gap-3 rounded bg-white p-5 text-sm shadow-sm">
        <label>
            状态
            <select class="mt-1 rounded border px-3 py-2" name="status">
                <option value="">全部状态</option>
                @foreach ($statuses as $item)
                    <option value="{{ $item }}" @selected($status === $item)>{{ $item }}</option>
                @endforeach
            </select>
        </label>
        <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
        <a class="rounded border px-4 py-2" href="{{ route('admin.cancel-requests.index') }}">重置</a>
    </form>

    <section class="space-y-4">
        @forelse ($requests as $request)
            <article class="rounded bg-white p-5 text-sm shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="font-semibold">#{{ $request->id }} {{ $request->host?->product?->name ?: '服务 #' . $request->host_id }}</div>
                        <div class="mt-1 text-slate-500">
                            客户：{{ $request->client?->username ?: '-' }} / 服务 ID：{{ $request->host_id }} / 类型：{{ $request->type }}
                        </div>
                    </div>
                    <span class="rounded bg-slate-100 px-3 py-1 text-xs text-slate-700">{{ $request->status }}</span>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded border bg-slate-50 p-3">
                        <div class="text-slate-500">客户原因</div>
                        <div class="mt-1 whitespace-pre-wrap">{{ $request->reason ?: '-' }}</div>
                    </div>
                    <div class="rounded border bg-slate-50 p-3">
                        <div class="text-slate-500">管理员备注</div>
                        <div class="mt-1 whitespace-pre-wrap">{{ $request->admin_notes ?: '-' }}</div>
                    </div>
                </div>

                @if ($request->status === 'pending')
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <form method="post" action="{{ route('admin.cancel-requests.approve', $request) }}" class="space-y-2">
                            @csrf
                            <textarea class="w-full rounded border px-3 py-2" name="admin_notes" rows="2" placeholder="批准备注（可选）"></textarea>
                            <button class="rounded bg-emerald-700 px-4 py-2 text-white">批准</button>
                        </form>
                        <form method="post" action="{{ route('admin.cancel-requests.reject', $request) }}" class="space-y-2">
                            @csrf
                            <textarea class="w-full rounded border px-3 py-2" name="admin_notes" rows="2" placeholder="拒绝原因" required></textarea>
                            <button class="rounded border border-red-300 px-4 py-2 text-red-700">拒绝</button>
                        </form>
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded bg-white p-8 text-center text-sm text-slate-500 shadow-sm">暂无取消申请</div>
        @endforelse
    </section>

    <div class="mt-4">{{ $requests->links() }}</div>
@endsection
