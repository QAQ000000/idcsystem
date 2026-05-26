@extends('layouts.admin')

@section('title', '邮件活动详情')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $campaign->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $campaign->subject }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.campaigns.index') }}">返回列表</a>
    </div>

    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <section class="space-y-6">
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">收件人</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $campaign->total_recipients }}</div>
                </div>
                <div class="rounded bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">已发送</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $campaign->sent_count }}</div>
                </div>
                <div class="rounded bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">打开</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $campaign->opened_count }}</div>
                </div>
                <div class="rounded bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">点击</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $campaign->clicked_count }}</div>
                </div>
            </div>

            <div class="rounded bg-white p-5 shadow-sm">
                <h2 class="mb-3 font-semibold">邮件内容</h2>
                <div class="prose max-w-none rounded border bg-slate-50 p-4">{!! $campaign->content !!}</div>
            </div>

            <div class="overflow-hidden rounded bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-3">客户</th>
                            <th class="px-4 py-3">状态</th>
                            <th class="px-4 py-3">发送</th>
                            <th class="px-4 py-3">打开</th>
                            <th class="px-4 py-3">点击</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($campaign->recipients as $recipient)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $recipient->client?->username }}</div>
                                    <div class="text-slate-500">{{ $recipient->client?->email }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $recipient->status }}</td>
                                <td class="px-4 py-3">{{ $recipient->sent_at?->format('Y-m-d H:i') ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $recipient->opened_at?->format('Y-m-d H:i') ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $recipient->clicked_at?->format('Y-m-d H:i') ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">活动操作</h2>
            <div class="space-y-2 text-sm">
                <p>状态：{{ $campaign->status }}</p>
                <p>计划时间：{{ $campaign->scheduled_at?->format('Y-m-d H:i') ?: '-' }}</p>
                <p>发送完成：{{ $campaign->sent_at?->format('Y-m-d H:i') ?: '-' }}</p>
            </div>

            @can('campaign.manage')
                @if (in_array($campaign->status, ['draft', 'scheduled'], true))
                    <form method="post" action="{{ route('admin.campaigns.send', $campaign) }}" class="mt-5">
                        @csrf
                        <button class="w-full rounded bg-slate-900 px-4 py-2 text-white">立即发送</button>
                    </form>

                    <form method="post" action="{{ route('admin.campaigns.schedule', $campaign) }}" class="mt-5 space-y-3">
                        @csrf
                        <label class="block text-sm">
                            安排时间
                            <input class="mt-1 w-full rounded border px-3 py-2" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', now()->addHour()->format('Y-m-d\TH:i')) }}" required>
                        </label>
                        <button class="w-full rounded border px-4 py-2">安排发送</button>
                    </form>
                @endif
            @endcan
        </aside>
    </div>
@endsection
