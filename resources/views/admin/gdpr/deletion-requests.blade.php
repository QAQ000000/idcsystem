@extends('layouts.admin')

@section('title', 'GDPR 删除请求')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">GDPR 删除请求</h1>
        <p class="mt-1 text-sm text-slate-500">审批客户删除请求；批准后系统会匿名化客户资料并关闭账户。</p>
    </div>

    @if (session('error'))
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <form method="get" class="mb-6 flex gap-3 rounded bg-white p-5 shadow-sm">
        <select class="rounded border px-3 py-2" name="status">
            <option value="">全部状态</option>
            @foreach ($statuses as $item)
                <option value="{{ $item }}" @selected($status === $item)>{{ $item }}</option>
            @endforeach
        </select>
        <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
        <a class="rounded border px-4 py-2" href="{{ route('admin.gdpr.deletion-requests.index') }}">重置</a>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">客户</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">原因</th>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($requests as $request)
                    <tr>
                        <td class="px-4 py-3">{{ $request->client?->username }} / {{ $request->client?->email }}</td>
                        <td class="px-4 py-3">{{ $request->status }}</td>
                        <td class="px-4 py-3">{{ $request->reason ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $request->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">
                            @if ($request->status === 'pending')
                                <div class="flex gap-2">
                                    <form method="post" action="{{ route('admin.gdpr.deletion-requests.approve', $request) }}">
                                        @csrf
                                        <input type="hidden" name="admin_notes" value="approved">
                                        <button class="text-red-600">批准</button>
                                    </form>
                                    <form method="post" action="{{ route('admin.gdpr.deletion-requests.reject', $request) }}">
                                        @csrf
                                        <input type="hidden" name="admin_notes" value="rejected">
                                        <button class="text-blue-600">拒绝</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-slate-400">无</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="5">暂无删除请求</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $requests->links() }}</div>
@endsection
