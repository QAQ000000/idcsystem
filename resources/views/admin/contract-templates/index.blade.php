@extends('layouts.admin')

@section('title', '合同模板')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">合同模板</h1>
            <p class="mt-1 text-sm text-slate-500">管理客户合同正文模板和变量占位符。</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.contract-templates.create') }}">新建模板</a>
    </div>

    <section class="rounded bg-white p-6 shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">更新时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($templates as $template)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $template->name }}</td>
                        <td class="px-4 py-3">{{ $template->active ? '启用' : '停用' }}</td>
                        <td class="px-4 py-3">{{ $template->updated_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="space-x-3 px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.contract-templates.edit', $template) }}">编辑</a>
                            <form method="post" action="{{ route('admin.contract-templates.destroy', $template) }}" class="inline" onsubmit="return confirm('确定删除该合同模板？')">
                                @csrf
                                @method('delete')
                                <button class="text-red-600">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="4">暂无合同模板</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-4">{{ $templates->links() }}</div>
    </section>
@endsection
