@extends('layouts.admin')

@section('title', '税率规则')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">税率规则</h1>
            <p class="mt-1 text-sm text-slate-500">按客户国家和州/省匹配账单税率。</p>
        </div>
        @can('tax_rule.manage')
            <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.tax-rules.create') }}">新建规则</a>
        @endcan
    </div>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-4">
        <label class="block text-sm">
            国家代码
            <input class="mt-1 w-full rounded border px-3 py-2 uppercase" name="country_code" maxlength="2" value="{{ $filters['country_code'] ?? '' }}" placeholder="CN">
        </label>
        <label class="block text-sm">
            状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部状态</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>启用</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>停用</option>
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.tax-rules.index') }}">重置</a>
        </div>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">国家</th>
                    <th class="px-4 py-3">州/省</th>
                    <th class="px-4 py-3">税率</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($taxRules as $taxRule)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $taxRule->name }}</td>
                        <td class="px-4 py-3">{{ $taxRule->country_code }}</td>
                        <td class="px-4 py-3">{{ $taxRule->state_code ?: '全部' }}</td>
                        <td class="px-4 py-3">{{ rtrim(rtrim(number_format((float) $taxRule->rate, 2, '.', ''), '0'), '.') }}%</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded px-2 py-0.5 text-xs {{ $taxRule->active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                {{ $taxRule->active ? '启用' : '停用' }}
                            </span>
                        </td>
                        <td class="space-x-3 px-4 py-3">
                            @can('tax_rule.manage')
                                <a class="text-blue-600" href="{{ route('admin.tax-rules.edit', $taxRule) }}">编辑</a>
                                <form method="post" action="{{ route('admin.tax-rules.destroy', $taxRule) }}" class="inline" onsubmit="return confirm('确定删除该税率规则？历史账单会保留已应用的税率快照。')">
                                    @csrf
                                    @method('delete')
                                    <button class="text-red-600">删除</button>
                                </form>
                            @else
                                <span class="text-slate-400">无</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无税率规则</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $taxRules->links() }}</div>
@endsection
