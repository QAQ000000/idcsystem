@extends('layouts.admin')

@section('title', '工单分配规则')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">工单分配规则</h1>

    <div class="mb-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">创建规则</h2>
        <form method="post" action="{{ route('admin.ticket-assignment-rules.store') }}" class="grid gap-4 md:grid-cols-4">
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
                策略
                <select class="mt-1 w-full rounded border px-3 py-2" name="strategy">
                    <option value="least_active">最少工单</option>
                    <option value="round_robin">轮询</option>
                    <option value="random">随机</option>
                </select>
            </label>
            <label class="text-sm md:col-span-2">
                可分配管理员
                <select class="mt-1 w-full rounded border px-3 py-2" name="admin_user_ids[]" multiple required size="4">
                    @foreach ($admins as $admin)
                        <option value="{{ $admin->id }}">{{ $admin->username }}（{{ $admin->assigned_ticket_count }}）</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" checked>
                启用
            </label>
            <div class="md:col-span-4">
                <button class="rounded bg-slate-900 px-4 py-2 text-white">保存规则</button>
            </div>
        </form>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">部门</th>
                    <th class="px-4 py-3 font-medium">策略</th>
                    <th class="px-4 py-3 font-medium">管理员</th>
                    <th class="px-4 py-3 font-medium">状态</th>
                    <th class="px-4 py-3 font-medium">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rules as $rule)
                    @php($selectedAdminIds = collect($rule->admin_user_ids)->map(fn ($id) => (int) $id)->all())
                    <tr>
                        <td class="px-4 py-3">{{ $rule->department?->name ?: '全部部门' }}</td>
                        <td class="px-4 py-3">
                            @switch($rule->strategy)
                                @case('round_robin') 轮询 @break
                                @case('random') 随机 @break
                                @default 最少工单
                            @endswitch
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                @foreach ($admins->whereIn('id', $selectedAdminIds) as $admin)
                                    <span class="rounded bg-slate-100 px-2 py-0.5">{{ $admin->username }}（{{ $admin->assigned_ticket_count }}）</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $rule->active ? '启用' : '停用' }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('admin.ticket-assignment-rules.update', $rule) }}" class="grid gap-2">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="department_id" value="{{ $rule->department_id }}">
                                <select class="rounded border px-2 py-1" name="strategy">
                                    <option value="least_active" @selected($rule->strategy === 'least_active')>最少工单</option>
                                    <option value="round_robin" @selected($rule->strategy === 'round_robin')>轮询</option>
                                    <option value="random" @selected($rule->strategy === 'random')>随机</option>
                                </select>
                                <select class="rounded border px-2 py-1" name="admin_user_ids[]" multiple required size="3">
                                    @foreach ($admins as $admin)
                                        <option value="{{ $admin->id }}" @selected(in_array((int) $admin->id, $selectedAdminIds, true))>{{ $admin->username }}（{{ $admin->assigned_ticket_count }}）</option>
                                    @endforeach
                                </select>
                                <label class="inline-flex items-center gap-2">
                                    <input type="hidden" name="active" value="0">
                                    <input type="checkbox" name="active" value="1" @checked($rule->active)>
                                    启用
                                </label>
                                <button class="text-left text-blue-600">更新</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="5">暂无数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rules->links() }}</div>
@endsection
