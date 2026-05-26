@extends('layouts.admin')

@section('title', '批量导入')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">批量导入</h1>
        <div class="flex gap-2 text-sm">
            <a class="rounded bg-slate-900 px-4 py-2 text-white" href="{{ route('admin.imports.create', 'clients') }}">导入客户</a>
            <a class="rounded border px-4 py-2" href="{{ route('admin.imports.create', 'products') }}">导入产品</a>
            <a class="rounded border px-4 py-2" href="{{ route('admin.imports.create', 'invoices') }}">导入账单</a>
        </div>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y">
            <thead class="bg-slate-50 text-left text-sm text-slate-600">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">进度</th>
                    <th class="px-4 py-3">管理员</th>
                    <th class="px-4 py-3">创建时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y text-sm">
                @forelse ($jobs as $job)
                    <tr>
                        <td class="px-4 py-3">{{ $job->id }}</td>
                        <td class="px-4 py-3">{{ $job->type }}</td>
                        <td class="px-4 py-3">{{ $job->status }}</td>
                        <td class="px-4 py-3">{{ $job->success_count }} 成功 / {{ $job->failed_count }} 失败 / {{ $job->total_rows }} 总计</td>
                        <td class="px-4 py-3">{{ $job->adminUser?->username ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $job->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.imports.show', $job) }}">查看</a></td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无导入任务</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $jobs->links() }}</div>
@endsection
