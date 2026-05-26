@extends('layouts.admin')

@section('title', '客户分组')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">客户分组</h1>
            <p class="mt-1 text-sm text-slate-500">维护客户分层和购物车结算时自动应用的分组折扣。</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.client-groups.create') }}">新建分组</a>
    </div>

    @if (session('error'))
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">折扣</th>
                    <th class="px-4 py-3">颜色</th>
                    <th class="px-4 py-3">客户数</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($groups as $group)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $group->name }}</td>
                        <td class="px-4 py-3">{{ rtrim(rtrim(number_format((float) $group->discount_percent, 2, '.', ''), '0'), '.') }}%</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-4 w-4 rounded-full border" style="background: {{ $group->color ?: '#64748b' }}"></span>
                                {{ $group->color ?: '-' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ $group->clients_count }}</td>
                        <td class="space-x-3 px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.client-groups.edit', $group) }}">编辑</a>
                            <form method="post" action="{{ route('admin.client-groups.destroy', $group) }}" class="inline" onsubmit="return confirm('确定删除该客户分组？')">
                                @csrf
                                @method('delete')
                                <button class="text-red-600">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="5">暂无客户分组</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $groups->links() }}</div>
@endsection
