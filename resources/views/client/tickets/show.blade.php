@extends('layouts.client')

@section('title', '工单详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $ticket->subject }}</h1>
    <div class="rounded bg-white p-5 shadow-sm">
        <p>工单号：{{ $ticket->ticket_number }}</p>
        <p>状态：{{ $ticket->status?->name }}</p>
        <p class="mt-4 whitespace-pre-line">{{ $ticket->message }}</p>
    </div>

    <div class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">回复记录</h2>
        <div class="divide-y">
            @forelse ($ticket->replies as $reply)
                <div class="py-3 text-sm">
                    <div class="text-zinc-500">{{ $reply->author_type }} #{{ $reply->author_id }} · {{ $reply->created_at }}</div>
                    <div class="mt-1 whitespace-pre-line">{{ $reply->message }}</div>
                </div>
            @empty
                <p class="text-sm text-zinc-500">暂无回复</p>
            @endforelse
        </div>

        <form method="post" action="{{ route('client.tickets.reply', $ticket) }}" class="mt-5">
            @csrf
            <label class="block text-sm">
                新回复
                <textarea class="mt-1 w-full rounded border px-3 py-2" name="message" rows="4" required></textarea>
            </label>
            <button class="mt-3 rounded bg-zinc-900 px-4 py-2 text-white">提交回复</button>
        </form>
    </div>
@endsection
