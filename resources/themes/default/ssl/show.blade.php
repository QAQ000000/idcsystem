@extends('theme::layouts.app')

@section('title', $certificate->domain)

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $certificate->domain }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $certificate->type }} / {{ $certificate->status }}</p>
        </div>
        <a class="text-sm text-blue-600" href="{{ route('client.ssl.index') }}">返回列表</a>
    </div>

    <section class="rounded bg-white p-5 shadow-sm">
        <dl class="grid gap-4 text-sm md:grid-cols-2">
            <div>
                <dt class="text-zinc-500">签发日期</dt>
                <dd class="mt-1">{{ $certificate->issue_date?->format('Y-m-d') ?: '-' }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">到期日期</dt>
                <dd class="mt-1">{{ $certificate->expiry_date?->format('Y-m-d') ?: '-' }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">自动续签</dt>
                <dd class="mt-1">{{ $certificate->auto_renew ? '开启' : '关闭' }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">关联主机</dt>
                <dd class="mt-1">{{ $certificate->host?->domain ?: '未关联' }}</dd>
            </div>
        </dl>

        <div class="mt-6 flex gap-3">
            @if ($certificate->certificate)
                <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.ssl.download', $certificate) }}">下载证书</a>
            @endif
            <form method="post" action="{{ route('client.ssl.deploy', $certificate) }}">
                @csrf
                <button class="rounded bg-zinc-900 px-4 py-2 text-sm text-white">部署到主机</button>
            </form>
        </div>
    </section>
@endsection
