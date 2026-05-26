@extends('theme::layouts.app')

@section('title', 'SSL 证书')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">SSL 证书</h1>
            <p class="mt-1 text-sm text-zinc-500">管理付费证书和 Let’s Encrypt 证书。</p>
        </div>
        <a class="rounded bg-zinc-900 px-4 py-2 text-sm text-white" href="{{ route('client.ssl.purchase') }}">购买证书</a>
    </div>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-zinc-600">
                <tr>
                    <th class="px-4 py-3">域名</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">到期日</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($certificates as $certificate)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $certificate->domain }}</td>
                        <td class="px-4 py-3">{{ $certificate->type }}</td>
                        <td class="px-4 py-3">{{ $certificate->status }}</td>
                        <td class="px-4 py-3">{{ $certificate->expiry_date?->format('Y-m-d') ?: '-' }}</td>
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route('client.ssl.show', $certificate) }}">管理</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-zinc-500" colspan="5">暂无 SSL 证书</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $certificates->links() }}</div>
@endsection
