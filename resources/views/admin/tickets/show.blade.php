@extends('layouts.admin')

@section('title', '工单详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $ticket->subject }}</h1>
    <div class="grid gap-6 md:grid-cols-[1fr_360px]">
        <div class="rounded bg-white p-5 shadow-sm">
            <p>工单号：{{ $ticket->ticket_number }}</p>
            <p>客户：{{ $ticket->client?->username }}</p>
            <p>状态：{{ $ticket->status?->name }}</p>
            <p class="mt-4 whitespace-pre-line">{{ $ticket->message }}</p>

            <div class="mt-6 divide-y">
                @foreach ($ticket->replies as $reply)
                    <div class="py-3 text-sm">
                        <div class="text-slate-500">{{ $reply->author_type }} #{{ $reply->author_id }} · {{ $reply->created_at }}</div>
                        <div class="mt-1 whitespace-pre-line">{{ $reply->message }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">工单操作</h2>
            <form method="post" action="{{ route('admin.tickets.reply', $ticket) }}">
                @csrf
                <label class="block text-sm">
                    回复内容
                    <textarea class="mt-1 w-full rounded border px-3 py-2" name="message" rows="5" required></textarea>
                </label>
                <button class="mt-3 w-full rounded bg-slate-900 px-4 py-2 text-white">回复工单</button>
            </form>

            <form method="post" action="{{ route('admin.tickets.assign', $ticket) }}" class="mt-5">
                @csrf
                <label class="block text-sm">
                    管理员 ID
                    <input class="mt-1 w-full rounded border px-3 py-2" name="admin_id" type="number" min="1" value="{{ $ticket->assigned_to ?: 1 }}">
                </label>
                <button class="mt-3 w-full rounded border px-4 py-2">分配工单</button>
            </form>

            <form method="post" action="{{ route('admin.tickets.close', $ticket) }}" class="mt-5">
                @csrf
                <button class="w-full rounded border border-red-300 px-4 py-2 text-red-700">关闭工单</button>
            </form>
        </div>
    </div>
@endsection
