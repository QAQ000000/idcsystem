@extends('theme::layouts.app')

@section('title', '消息中心')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">消息中心</h1>
            <p class="mt-1 text-sm text-slate-500">未读 {{ $unreadCount }} 条</p>
        </div>
        <form method="post" action="{{ route('client.notifications.read-all') }}">
            @csrf
            <button class="rounded border px-4 py-2 text-sm">全部已读</button>
        </form>
    </div>

    <div class="space-y-3">
        @forelse ($items as $notification)
            <div class="rounded bg-white p-5 shadow-sm @if (!$notification->read) border-l-4 border-blue-600 @endif">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $notification->type }}</span>
                            <h2 class="font-semibold">{{ $notification->title }}</h2>
                        </div>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $notification->content }}</p>
                        <p class="mt-3 text-xs text-slate-500">{{ userTime($notification->created_at, 'Y-m-d H:i') }}</p>
                    </div>
                    @if (!$notification->read)
                        <form method="post" action="{{ route('client.notifications.read', $notification) }}">
                            @csrf
                            <button class="rounded border px-3 py-1 text-sm">已读</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded bg-white p-8 text-center text-sm text-slate-500 shadow-sm">暂无消息</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $items->links() }}</div>
@endsection
