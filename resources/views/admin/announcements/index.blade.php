@extends('layouts.admin')

@section('title', '公告管理')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">公告管理</h1>
            <p class="mt-1 text-sm text-slate-500">发布客户仪表盘可见的维护、新功能和重要通知。</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.announcements.create') }}">新建公告</a>
    </div>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">标题</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">有效期</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($announcements as $announcement)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $announcement->title }}</td>
                        <td class="px-4 py-3">{{ ['info' => '信息', 'warning' => '警告', 'maintenance' => '维护'][$announcement->type] ?? $announcement->type }}</td>
                        <td class="px-4 py-3">{{ $announcement->active ? '启用' : '停用' }}</td>
                        <td class="px-4 py-3">
                            {{ $announcement->starts_at?->format('Y-m-d H:i') ?: '立即' }}
                            -
                            {{ $announcement->ends_at?->format('Y-m-d H:i') ?: '长期' }}
                        </td>
                        <td class="space-x-3 px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.announcements.edit', $announcement) }}">编辑</a>
                            <form class="inline" method="post" action="{{ route('admin.announcements.toggle', $announcement) }}">
                                @csrf
                                <button class="text-amber-700">{{ $announcement->active ? '停用' : '启用' }}</button>
                            </form>
                            <form class="inline" method="post" action="{{ route('admin.announcements.destroy', $announcement) }}" onsubmit="return confirm('确定删除该公告？')">
                                @csrf
                                @method('delete')
                                <button class="text-red-600">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="5">暂无公告</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $announcements->links() }}</div>
@endsection
