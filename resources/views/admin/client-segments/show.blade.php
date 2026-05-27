@extends('layouts.admin')

@section('title', $clientSegment->name)

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $clientSegment->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $clientSegment->description ?: '客户分群' }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.client-segments.index') }}">返回</a>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">类型</div>
            <div class="mt-1 text-2xl font-semibold">{{ $clientSegment->type === 'dynamic' ? '动态' : '静态' }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">客户数</div>
            <div class="mt-1 text-2xl font-semibold">{{ $clientSegment->clients_count }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">最后更新</div>
            <div class="mt-1 text-lg font-semibold">{{ $clientSegment->last_calculated_at?->format('Y-m-d H:i') ?: '-' }}</div>
        </div>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">更新分群</h2>
            <form method="post" action="{{ route('admin.client-segments.calculate', $clientSegment) }}">
                @csrf
                <button class="rounded bg-slate-900 px-4 py-2 text-white">重新计算</button>
            </form>
            @if ($clientSegment->isDynamic())
                <pre class="mt-4 overflow-auto rounded bg-slate-950 p-4 text-xs text-white">{{ json_encode($clientSegment->rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </section>

        @if (!$clientSegment->isDynamic())
            <section class="rounded bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-semibold">添加客户</h2>
                <form method="post" action="{{ route('admin.client-segments.members.store', $clientSegment) }}" class="grid gap-3">
                    @csrf
                    <label class="text-sm">
                        客户 ID
                        <textarea class="mt-1 w-full rounded border px-3 py-2" name="client_ids" rows="4" required placeholder="每行或用逗号填写一个客户 ID"></textarea>
                    </label>
                    @error('client_ids')<span class="text-red-700">{{ $message }}</span>@enderror
                    <button class="w-fit rounded bg-slate-900 px-4 py-2 text-white">添加到分群</button>
                </form>
            </section>
        @endif
    </div>

    <section class="rounded bg-white shadow-sm">
        <div class="border-b px-5 py-4 font-semibold">分群客户</div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">用户名</th>
                    <th class="px-4 py-3">邮箱</th>
                    <th class="px-4 py-3">加入时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($members as $client)
                    <tr>
                        <td class="px-4 py-3">{{ $client->id }}</td>
                        <td class="px-4 py-3">{{ $client->username }}</td>
                        <td class="px-4 py-3">{{ $client->email }}</td>
                        <td class="px-4 py-3">{{ $client->pivot?->added_at }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.clients.show', $client) }}">查看</a>
                            @if (!$clientSegment->isDynamic())
                                <form method="post" action="{{ route('admin.client-segments.members.destroy', [$clientSegment, $client]) }}" class="ml-3 inline">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-red-700">移出</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="5">暂无客户</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $members->links() }}</div>
@endsection
