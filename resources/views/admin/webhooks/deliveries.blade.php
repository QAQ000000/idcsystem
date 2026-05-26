@extends('layouts.admin')

@section('title', 'Webhook 投递记录')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $webhook->name }} 投递记录</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $webhook->url }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.webhooks.index') }}">返回列表</a>
    </div>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">事件</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">HTTP</th>
                    <th class="px-4 py-3">响应</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($deliveries as $delivery)
                    <tr>
                        <td class="px-4 py-3">{{ $delivery->delivered_at?->format('Y-m-d H:i:s') ?: $delivery->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $delivery->event }}</td>
                        <td class="px-4 py-3">{{ $delivery->status }}</td>
                        <td class="px-4 py-3">{{ $delivery->status_code ?: '-' }}</td>
                        <td class="px-4 py-3 max-w-lg truncate">{{ $delivery->response ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="5">暂无投递记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $deliveries->links() }}</div>
@endsection
