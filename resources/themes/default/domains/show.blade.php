@extends('theme::layouts.app')

@section('title', $domain->domain)

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $domain->domain }}</h1>
            <p class="mt-1 text-sm text-zinc-500">状态 {{ $domain->status }}，到期日 {{ $domain->expiry_date?->format('Y-m-d') }}</p>
        </div>
        <a class="text-sm text-blue-600" href="{{ route('client.domains.index') }}">返回列表</a>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">DNS 服务器</h2>
            <form method="post" action="{{ route('client.domains.nameservers', $domain) }}" class="space-y-3">
                @csrf
                @for ($i = 0; $i < 4; $i++)
                    <input class="w-full rounded border px-3 py-2" name="nameservers[]" value="{{ old('nameservers.' . $i, $domain->nameservers[$i] ?? '') }}" placeholder="ns{{ $i + 1 }}.example.com">
                @endfor
                <button class="rounded bg-zinc-900 px-4 py-2 text-white">保存 DNS</button>
            </form>
        </section>

        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">续费</h2>
            <dl class="mb-4 grid grid-cols-2 gap-3 text-sm">
                <dt class="text-zinc-500">WHOIS 隐私</dt>
                <dd>{{ $domain->whois_privacy ? '开启' : '关闭' }}</dd>
                <dt class="text-zinc-500">注册商</dt>
                <dd>{{ $domain->registrar ?: 'manual' }}</dd>
                <dt class="text-zinc-500">注册日期</dt>
                <dd>{{ $domain->registration_date?->format('Y-m-d') }}</dd>
            </dl>
            <form method="post" action="{{ route('client.domains.renew', $domain) }}" class="flex gap-3">
                @csrf
                <input class="w-28 rounded border px-3 py-2" type="number" min="1" max="10" name="years" value="1">
                <button class="rounded bg-zinc-900 px-4 py-2 text-white">生成续费账单</button>
            </form>
        </section>
    </div>
@endsection
