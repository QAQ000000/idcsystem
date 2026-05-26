@extends('layouts.admin')

@section('title', '标签客户')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $tag->name }} 客户</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.client-tags.index') }}">返回标签</a>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">用户名</th>
                    <th class="px-4 py-3">邮箱</th>
                    <th class="px-4 py-3">打标时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($clients as $client)
                    <tr>
                        <td class="px-4 py-3">{{ $client->id }}</td>
                        <td class="px-4 py-3">{{ $client->username }}</td>
                        <td class="px-4 py-3">{{ $client->email }}</td>
                        <td class="px-4 py-3">{{ $client->pivot?->tagged_at }}</td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.clients.show', $client) }}">查看</a></td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="5">暂无客户</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $clients->links() }}</div>
@endsection
