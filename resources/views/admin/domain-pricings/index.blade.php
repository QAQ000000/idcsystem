@extends('layouts.admin')

@section('title', 'TLD 价格')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">TLD 价格</h1>
        <p class="mt-1 text-sm text-slate-500">维护域名注册、续费和转入价格。</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('admin.domain-pricings.store') }}" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-6">
        @csrf
        <input class="rounded border px-3 py-2" name="tld" placeholder=".com" required>
        <input class="rounded border px-3 py-2" type="number" min="0" step="0.01" name="register_price" placeholder="注册价" required>
        <input class="rounded border px-3 py-2" type="number" min="0" step="0.01" name="renew_price" placeholder="续费价" required>
        <input class="rounded border px-3 py-2" type="number" min="0" step="0.01" name="transfer_price" placeholder="转入价" required>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="active" value="1" checked>
            启用
        </label>
        <button class="rounded bg-slate-900 px-4 py-2 text-white">新增</button>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">后缀</th>
                    <th class="px-4 py-3">注册价</th>
                    <th class="px-4 py-3">续费价</th>
                    <th class="px-4 py-3">转入价</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($pricings as $pricing)
                    <tr>
                        <form method="post" action="{{ route('admin.domain-pricings.update', $pricing) }}">
                            @csrf
                            @method('put')
                            <td class="px-4 py-3">
                                <input class="w-24 rounded border px-2 py-1" name="tld" value="{{ $pricing->tld }}">
                            </td>
                            <td class="px-4 py-3"><input class="w-28 rounded border px-2 py-1" type="number" min="0" step="0.01" name="register_price" value="{{ $pricing->register_price }}"></td>
                            <td class="px-4 py-3"><input class="w-28 rounded border px-2 py-1" type="number" min="0" step="0.01" name="renew_price" value="{{ $pricing->renew_price }}"></td>
                            <td class="px-4 py-3"><input class="w-28 rounded border px-2 py-1" type="number" min="0" step="0.01" name="transfer_price" value="{{ $pricing->transfer_price }}"></td>
                            <td class="px-4 py-3">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="active" value="1" @checked($pricing->active)>
                                    启用
                                </label>
                            </td>
                            <td class="px-4 py-3">
                                <button class="text-blue-600">保存</button>
                            </td>
                        </form>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无 TLD 价格</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $pricings->links() }}</div>
@endsection
