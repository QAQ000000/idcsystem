@extends('layouts.admin')

@section('title', 'Webhooks')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Webhooks</h1>
            <p class="mt-1 text-sm text-slate-500">将订单、账单和服务状态事件推送到外部系统。</p>
        </div>
        <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.webhooks.create') }}">新建 Webhook</a>
    </div>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">URL</th>
                    <th class="px-4 py-3">事件</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">投递</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($webhooks as $webhook)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $webhook->name }}</td>
                        <td class="px-4 py-3 max-w-md truncate">{{ $webhook->url }}</td>
                        <td class="px-4 py-3">{{ collect($webhook->events)->map(fn ($event) => $events[$event] ?? $event)->join('、') }}</td>
                        <td class="px-4 py-3">{{ $webhook->active ? '启用' : '停用' }}</td>
                        <td class="px-4 py-3">{{ $webhook->deliveries_count }}</td>
                        <td class="space-x-3 px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.webhooks.deliveries', $webhook) }}">投递记录</a>
                            <a class="text-blue-600" href="{{ route('admin.webhooks.edit', $webhook) }}">编辑</a>
                            <form class="inline" method="post" action="{{ route('admin.webhooks.test', $webhook) }}">
                                @csrf
                                <button class="text-emerald-700">测试</button>
                            </form>
                            <form class="inline" method="post" action="{{ route('admin.webhooks.destroy', $webhook) }}" onsubmit="return confirm('确定删除该 Webhook？')">
                                @csrf
                                @method('delete')
                                <button class="text-red-600">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无 Webhook</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $webhooks->links() }}</div>
@endsection
