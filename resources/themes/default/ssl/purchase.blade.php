@extends('theme::layouts.app')

@section('title', '购买 SSL 证书')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">购买 SSL 证书</h1>
        <p class="mt-1 text-sm text-zinc-500">付费证书会生成账单，Let’s Encrypt 证书会立即签发。</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('client.ssl.store') }}" class="grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-2">
        @csrf
        <label class="block text-sm">
            域名
            <input class="mt-1 w-full rounded border px-3 py-2" name="domain" value="{{ old('domain') }}" required placeholder="www.example.com">
        </label>
        <label class="block text-sm">
            类型
            <select class="mt-1 w-full rounded border px-3 py-2" name="type">
                <option value="paid" @selected(old('type') === 'paid')>付费证书</option>
                <option value="letsencrypt" @selected(old('type') === 'letsencrypt')>Let’s Encrypt</option>
            </select>
        </label>
        <label class="block text-sm">
            年限
            <input class="mt-1 w-full rounded border px-3 py-2" type="number" min="1" max="10" name="years" value="{{ old('years', 1) }}">
        </label>
        <label class="block text-sm">
            关联主机
            <select class="mt-1 w-full rounded border px-3 py-2" name="host_id">
                <option value="">暂不关联</option>
                @foreach ($hosts as $host)
                    <option value="{{ $host->id }}" @selected((int) old('host_id') === $host->id)>{{ $host->domain }} #{{ $host->id }}</option>
                @endforeach
            </select>
        </label>
        <div class="md:col-span-2">
            <button class="rounded bg-zinc-900 px-4 py-2 text-white">提交</button>
        </div>
    </form>
@endsection
