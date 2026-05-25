@extends('layouts.admin')

@section('title', '发票申请')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">发票申请</h1>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-3">
        <label class="block text-sm">
            状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部</option>
                @foreach (['pending' => '待处理', 'processing' => '处理中', 'issued' => '已开具', 'rejected' => '已拒绝'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.invoice-receipts.index') }}">重置</a>
        </div>
    </form>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">客户</th>
                    <th class="px-4 py-3">账单</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">抬头</th>
                    <th class="px-4 py-3">邮箱</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($receipts as $receipt)
                    <tr>
                        <td class="px-4 py-3">{{ $receipt->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">{{ $receipt->client?->username ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $receipt->invoice?->invoice_number ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $receipt->type === 'vat' ? '增值税' : '普通' }}</td>
                        <td class="px-4 py-3">{{ $receipt->title }}</td>
                        <td class="px-4 py-3">{{ $receipt->email }}</td>
                        <td class="px-4 py-3">{{ $receipt->status }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('admin.invoice-receipts.update', $receipt) }}" class="grid gap-2">
                                @csrf
                                @method('PUT')
                                <select class="rounded border px-2 py-1" name="status">
                                    @foreach (['processing' => '处理中', 'issued' => '已开具', 'rejected' => '拒绝'] as $value => $label)
                                        <option value="{{ $value }}" @selected($receipt->status === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input class="rounded border px-2 py-1" name="admin_notes" value="{{ $receipt->admin_notes }}" placeholder="备注/拒绝原因">
                                <button class="rounded bg-slate-900 px-3 py-1 text-white">更新</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="8">暂无发票申请</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $receipts->links() }}</div>
@endsection
