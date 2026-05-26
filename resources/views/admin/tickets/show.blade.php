@extends('layouts.admin')

@section('title', '工单详情')

@section('content')
    @php($adminUser = auth('admin')->user())
    @php($canAdmin = fn (string $permission): bool => $adminUser && ($adminUser->hasRole('super-admin') || $adminUser->can($permission)))
    @php($isClosed = $ticket->status?->name === 'Closed')

    <h1 class="mb-4 text-2xl font-semibold">{{ $ticket->subject }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p>工单号：{{ $ticket->ticket_number }}</p>
            <p>
                客户：{{ $ticket->client?->username }}
                @if ($ticket->client?->trashed())
                    <span class="ml-1 inline-flex rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-700">已删除</span>
                @endif
            </p>
            <p>状态：{{ $ticket->status?->name }}</p>
            <p class="mt-4 whitespace-pre-line">{{ $ticket->message }}</p>

            <div class="mt-6 divide-y">
                @foreach ($ticket->replies as $reply)
                    <div class="py-3 text-sm">
                        <div class="text-slate-500">{{ $reply->author_type }} #{{ $reply->author_id }} · {{ $reply->created_at }}</div>
                        <div class="mt-1 whitespace-pre-line">{{ $reply->message }}</div>
                        @if (is_array($reply->attachment) && $reply->attachment !== [])
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($reply->attachment as $index => $attachment)
                                    <a class="rounded border px-3 py-1 text-xs text-blue-600" href="{{ route('admin.tickets.attachments.download', [$ticket, $reply, $index]) }}">
                                        {{ $attachment['name'] ?? '附件 ' . ($index + 1) }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">工单操作</h2>
            @if (!$canAdmin('ticket.manage'))
                <p class="text-sm text-slate-500">当前账号没有工单操作权限。</p>
            @elseif ($ticket->client?->trashed())
                <p class="mb-4 text-sm text-slate-500">客户已删除，不能继续回复该工单。</p>
            @elseif ($isClosed)
                <p class="mb-4 text-sm text-slate-500">工单已关闭，不能继续回复。</p>
            @else
                <form method="post" action="{{ route('admin.tickets.reply', $ticket) }}" enctype="multipart/form-data">
                    @csrf
                    <label class="block text-sm">
                        回复内容
                        <textarea class="mt-1 w-full rounded border px-3 py-2" name="message" rows="5" required></textarea>
                    </label>
                    <label class="mt-3 block text-sm">
                        附件
                        <input class="mt-1 w-full rounded border px-3 py-2" type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.txt,.zip">
                    </label>
                    <button class="mt-3 w-full rounded bg-slate-900 px-4 py-2 text-white">回复工单</button>
                </form>
            @endif

            @if ($canAdmin('ticket.manage'))
                <form method="post" action="{{ route('admin.tickets.assign', $ticket) }}" class="mt-5">
                    @csrf
                    <label class="block text-sm">
                        管理员 ID
                        <input class="mt-1 w-full rounded border px-3 py-2" name="admin_id" type="number" min="1" value="{{ $ticket->assigned_to ?: 1 }}">
                    </label>
                    <button class="mt-3 w-full rounded border px-4 py-2">分配工单</button>
                </form>

                @if (!$isClosed)
                    <form method="post" action="{{ route('admin.tickets.close', $ticket) }}" class="mt-5">
                        @csrf
                        <button class="w-full rounded border border-red-300 px-4 py-2 text-red-700">关闭工单</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
@endsection
