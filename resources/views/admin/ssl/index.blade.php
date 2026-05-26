@extends('layouts.admin')

@section('title', 'SSL 证书')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">SSL 证书</h1>
        <p class="mt-1 text-sm text-slate-500">查看客户证书状态，并手动签发 Let’s Encrypt 证书。</p>
    </div>

    @if (session('error'))
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-4">
        <label class="block text-sm">
            类型
            <select class="mt-1 w-full rounded border px-3 py-2" name="type">
                <option value="">全部类型</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部状态</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.ssl.index') }}">重置</a>
        </div>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">域名</th>
                    <th class="px-4 py-3">客户</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">到期日</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($certificates as $certificate)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $certificate->domain }}</td>
                        <td class="px-4 py-3">{{ $certificate->client?->username }} / {{ $certificate->client?->email }}</td>
                        <td class="px-4 py-3">{{ $certificate->type }}</td>
                        <td class="px-4 py-3">{{ $certificate->status }}</td>
                        <td class="px-4 py-3">{{ $certificate->expiry_date?->format('Y-m-d') ?: '-' }}</td>
                        <td class="px-4 py-3">
                            @if ($certificate->type === 'letsencrypt')
                                <form method="post" action="{{ route('admin.ssl.issue', $certificate) }}">
                                    @csrf
                                    <button class="text-blue-600">签发</button>
                                </form>
                            @else
                                <span class="text-slate-400">无</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无 SSL 证书</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $certificates->links() }}</div>
@endsection
