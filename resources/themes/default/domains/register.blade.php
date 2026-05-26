@extends('theme::layouts.app')

@section('title', '注册域名')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">注册域名</h1>
        <p class="mt-1 text-sm text-zinc-500">先查询域名可用性，再创建注册账单。</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <form method="get" action="{{ route('client.domains.register') }}" class="mb-6 flex gap-3 rounded bg-white p-5 shadow-sm">
        <input class="w-full rounded border px-3 py-2" name="domain" value="{{ $domain }}" placeholder="example.com">
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">检查</button>
    </form>

    @if ($domain !== '' && $availability !== null)
        <div class="mb-6 rounded border px-4 py-3 text-sm {{ $availability ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' }}">
            {{ $availability ? '域名可注册' : '域名不可注册或格式不正确' }}
        </div>
    @endif

    <form method="post" action="{{ route('client.domains.store') }}" class="grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-2">
        @csrf
        <label class="block text-sm">
            域名
            <input class="mt-1 w-full rounded border px-3 py-2" name="domain" value="{{ old('domain', $domain) }}" required placeholder="example.com">
        </label>
        <label class="block text-sm">
            年限
            <input class="mt-1 w-full rounded border px-3 py-2" type="number" min="1" max="10" name="years" value="{{ old('years', 1) }}" required>
        </label>
        <label class="flex items-center gap-2 text-sm md:col-span-2">
            <input type="checkbox" name="whois_privacy" value="1" @checked(old('whois_privacy'))>
            开启 WHOIS 隐私保护
        </label>
        <div class="md:col-span-2">
            <button class="rounded bg-zinc-900 px-4 py-2 text-white">创建注册账单</button>
        </div>
    </form>

    <section class="mt-8 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-lg font-semibold">可注册后缀</h2>
        <div class="grid gap-3 md:grid-cols-3">
            @forelse ($pricings as $pricing)
                <div class="rounded border p-4 text-sm">
                    <div class="font-semibold">{{ $pricing->tld }}</div>
                    <div class="mt-2 text-zinc-600">注册 {{ $pricing->register_price }} / 续费 {{ $pricing->renew_price }}</div>
                </div>
            @empty
                <p class="text-sm text-zinc-500">暂无开放注册的后缀。</p>
            @endforelse
        </div>
    </section>
@endsection
