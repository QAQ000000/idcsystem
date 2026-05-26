@extends('theme::layouts.app')

@section('title', 'API Token')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">API Token</h1>

    @if (session('plain_text_token'))
        <div class="mb-6 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="font-semibold">新 Token</div>
            <code class="mt-2 block break-all rounded bg-white p-3">{{ session('plain_text_token') }}</code>
        </div>
    @endif

    <section class="mb-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">创建 Token</h2>
        <form method="post" action="{{ route('client.api-tokens.store') }}" class="grid gap-4">
            @csrf
            <label class="text-sm">
                名称
                <input class="mt-1 w-full rounded border px-3 py-2" name="name" required maxlength="100">
            </label>
            <div class="grid gap-2 md:grid-cols-2">
                @foreach ($abilities as $ability => $label)
                    <label class="inline-flex items-center gap-2 rounded border px-3 py-2 text-sm">
                        <input type="checkbox" name="abilities[]" value="{{ $ability }}">
                        <span>{{ $label }} <span class="text-slate-500">{{ $ability }}</span></span>
                    </label>
                @endforeach
            </div>
            <button class="w-fit rounded bg-slate-900 px-4 py-2 text-white">创建 Token</button>
        </form>
    </section>

    <section class="rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">Token 列表</h2>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">权限</th>
                    <th class="px-4 py-3">最后使用</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tokens as $token)
                    <tr>
                        <td class="px-4 py-3">{{ $token->name }}</td>
                        <td class="px-4 py-3">{{ implode(', ', $token->abilities ?? []) }}</td>
                        <td class="px-4 py-3">{{ $token->last_used_at?->format('Y-m-d H:i') ?: '-' }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('client.api-tokens.destroy', $token) }}">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-700">撤销</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="4">暂无 Token</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
@endsection
