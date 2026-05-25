@extends('layouts.admin')

@section('title', '账单列表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">账单列表</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.export.invoices', request()->query()) }}">导出 CSV</a>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">ID</th>
                    <th class="px-4 py-3 font-medium">账单号</th>
                    <th class="px-4 py-3 font-medium">状态</th>
                    <th class="px-4 py-3 font-medium">总额</th>
                    <th class="px-4 py-3 font-medium">客户</th>
                    <th class="px-4 py-3 font-medium">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($invoices as $invoice)
                    <tr>
                        <td class="px-4 py-3">{{ $invoice->id }}</td>
                        <td class="px-4 py-3">{{ $invoice->invoice_number }}</td>
                        <td class="px-4 py-3">{{ $invoice->status }}</td>
                        <td class="px-4 py-3">{{ $invoice->total }}</td>
                        <td class="px-4 py-3">
                            {{ $invoice->client?->username ?: '-' }}
                            @if ($invoice->client?->trashed())
                                <span class="ml-1 inline-flex rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-700">已删除</span>
                            @endif
                        </td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.invoices.show', $invoice) }}">查看</a></td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>
@endsection
