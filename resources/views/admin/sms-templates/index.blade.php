@extends('layouts.admin')

@section('title', '短信模板')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">短信模板</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.notifications.index') }}">返回通知中心</a>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">模板</th>
                    <th class="px-4 py-3">内容</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($templates as $template)
                    <tr>
                        <td class="px-4 py-3">{{ $template->name }}</td>
                        <td class="px-4 py-3">{{ $template->content }}</td>
                        <td class="px-4 py-3">{{ $template->enabled ? '启用' : '禁用' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.sms-templates.edit', $template) }}">编辑</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $templates->links() }}</div>
@endsection
