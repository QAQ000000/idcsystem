@extends('layouts.admin')

@section('title', '邮件活动')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">邮件活动</h1>
            <p class="mt-1 text-sm text-slate-500">创建、安排和追踪面向客户分组的营销邮件。</p>
        </div>
        @can('campaign.manage')
            <a class="rounded bg-slate-900 px-4 py-2 text-sm text-white" href="{{ route('admin.campaigns.create') }}">新建活动</a>
        @endcan
    </div>

    <form method="get" class="mb-6 flex gap-3 rounded bg-white p-5 shadow-sm">
        <select class="rounded border px-3 py-2 text-sm" name="status">
            <option value="">全部状态</option>
            @foreach (['draft' => '草稿', 'scheduled' => '已安排', 'sending' => '发送中', 'sent' => '已发送', 'cancelled' => '已取消'] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
        <a class="rounded border px-4 py-2" href="{{ route('admin.campaigns.index') }}">重置</a>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">活动</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">收件人</th>
                    <th class="px-4 py-3">发送/打开/点击</th>
                    <th class="px-4 py-3">计划时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($campaigns as $campaign)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $campaign->name }}</div>
                            <div class="text-slate-500">{{ $campaign->subject }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $campaign->status }}</td>
                        <td class="px-4 py-3">{{ $campaign->total_recipients }}</td>
                        <td class="px-4 py-3">{{ $campaign->sent_count }} / {{ $campaign->opened_count }} / {{ $campaign->clicked_count }}</td>
                        <td class="px-4 py-3">{{ $campaign->scheduled_at?->format('Y-m-d H:i') ?: '-' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('admin.campaigns.show', $campaign) }}">详情</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无邮件活动</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $campaigns->links() }}</div>
@endsection
