@extends('layouts.admin')

@section('title', '客户分群')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">客户分群</h1>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.client-segments.create') }}">新建分群</a>
    </div>

    <section class="rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">客户数</th>
                    <th class="px-4 py-3">最后更新</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($segments as $segment)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $segment->name }}</div>
                            <div class="text-slate-500">{{ $segment->description ?: '-' }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $segment->type === 'dynamic' ? '动态' : '静态' }}</td>
                        <td class="px-4 py-3">{{ $segment->clients_count }}</td>
                        <td class="px-4 py-3">{{ $segment->last_calculated_at?->format('Y-m-d H:i') ?: '-' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.client-segments.show', $segment) }}">查看</a>
                            <form method="post" action="{{ route('admin.client-segments.destroy', $segment) }}" class="ml-3 inline">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-700">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="5">暂无客户分群</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $segments->links() }}</div>
@endsection
