@extends('theme::layouts.app')

@section('title', '隐私')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">隐私与数据</h1>
        <p class="mt-1 text-sm text-zinc-500">当前隐私政策版本：{{ $policyVersion }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">数据导出</h2>
                <form method="post" action="{{ route('client.account.export-data') }}">
                    @csrf
                    <button class="rounded bg-zinc-900 px-4 py-2 text-sm text-white">请求导出</button>
                </form>
            </div>
            <div class="space-y-3 text-sm">
                @forelse ($exportRequests as $request)
                    <div class="flex items-center justify-between rounded border p-3">
                        <span>#{{ $request->id }} / {{ $request->status }}</span>
                        @if ($request->status === 'completed')
                            <a class="text-blue-600" href="{{ route('client.account.export-data.download', $request) }}">下载</a>
                        @endif
                    </div>
                @empty
                    <p class="text-zinc-500">暂无导出请求。</p>
                @endforelse
            </div>
        </section>

        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">删除账户</h2>
            <form method="post" action="{{ route('client.account.delete-account') }}" class="space-y-3">
                @csrf
                <textarea class="w-full rounded border px-3 py-2 text-sm" name="reason" rows="4" maxlength="2000" placeholder="删除原因（可选）">{{ old('reason') }}</textarea>
                <button class="rounded bg-red-700 px-4 py-2 text-sm text-white">提交删除请求</button>
            </form>
            <div class="mt-4 space-y-3 text-sm">
                @forelse ($deletionRequests as $request)
                    <div class="rounded border p-3">#{{ $request->id }} / {{ $request->status }}</div>
                @empty
                    <p class="text-zinc-500">暂无删除请求。</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-lg font-semibold">隐私政策同意记录</h2>
        <div class="space-y-3 text-sm">
            @forelse ($consents as $consent)
                <div class="rounded border p-3">版本 {{ $consent->policy_version }} / {{ $consent->consented_at?->format('Y-m-d H:i') }} / {{ $consent->ip }}</div>
            @empty
                <p class="text-zinc-500">暂无同意记录。</p>
            @endforelse
        </div>
    </section>
@endsection
