@extends('layouts.admin')

@section('title', '工单 SLA')

@section('content')
    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">工单 SLA</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.ticket-slas.statistics') }}">统计报表</a>
    </div>

    <div class="mb-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">创建规则</h2>
        <form method="post" action="{{ route('admin.ticket-slas.store') }}" class="grid gap-4 md:grid-cols-5">
            @csrf
            <label class="text-sm">
                部门
                <select class="mt-1 w-full rounded border px-3 py-2" name="department_id">
                    <option value="">全部部门</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                优先级
                <select class="mt-1 w-full rounded border px-3 py-2" name="priority">
                    @foreach (['Low', 'Medium', 'High', 'Urgent'] as $priority)
                        <option value="{{ $priority }}">{{ $priority }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">
                首响分钟
                <input class="mt-1 w-full rounded border px-3 py-2" name="response_time_minutes" type="number" min="1" required>
            </label>
            <label class="text-sm">
                解决分钟
                <input class="mt-1 w-full rounded border px-3 py-2" name="resolution_time_minutes" type="number" min="1" required>
            </label>
            <label class="flex items-end gap-2 text-sm">
                <input type="hidden" name="active" value="0">
                <input class="mb-3" type="checkbox" name="active" value="1" checked>
                <span class="mb-2">启用</span>
            </label>
            <div class="md:col-span-5">
                <button class="rounded bg-slate-900 px-4 py-2 text-white">保存规则</button>
            </div>
        </form>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">部门</th>
                    <th class="px-4 py-3 font-medium">优先级</th>
                    <th class="px-4 py-3 font-medium">首响</th>
                    <th class="px-4 py-3 font-medium">解决</th>
                    <th class="px-4 py-3 font-medium">状态</th>
                    <th class="px-4 py-3 font-medium">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($slas as $sla)
                    <tr>
                        <form method="post" action="{{ route('admin.ticket-slas.update', $sla) }}">
                            @csrf
                            @method('PUT')
                            <td class="px-4 py-3">
                                <select class="w-full rounded border px-3 py-2" name="department_id">
                                    <option value="">全部部门</option>
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}" @selected((int) $sla->department_id === (int) $department->id)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-4 py-3">
                                <select class="w-full rounded border px-3 py-2" name="priority">
                                    @foreach (['Low', 'Medium', 'High', 'Urgent'] as $priority)
                                        <option value="{{ $priority }}" @selected($sla->priority === $priority)>{{ $priority }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-4 py-3"><input class="w-28 rounded border px-3 py-2" name="response_time_minutes" type="number" min="1" value="{{ $sla->response_time_minutes }}"></td>
                            <td class="px-4 py-3"><input class="w-28 rounded border px-3 py-2" name="resolution_time_minutes" type="number" min="1" value="{{ $sla->resolution_time_minutes }}"></td>
                            <td class="px-4 py-3">
                                <input type="hidden" name="active" value="0">
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" name="active" value="1" @checked($sla->active)>
                                    启用
                                </label>
                            </td>
                            <td class="px-4 py-3"><button class="text-blue-600">更新</button></td>
                        </form>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $slas->links() }}</div>
@endsection
