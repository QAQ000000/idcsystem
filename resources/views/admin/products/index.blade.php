@extends('layouts.admin')

@section('title', '产品列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">产品列表</h1>
    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">库存</th>
                    <th class="px-4 py-3">预警</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr>
                        <td class="px-4 py-3">{{ $product->id }}</td>
                        <td class="px-4 py-3">{{ $product->name }}</td>
                        <td class="px-4 py-3">{{ $product->type }}</td>
                        <td class="px-4 py-3">{{ $product->stock_control ? $product->stock_qty : '不限' }}</td>
                        <td class="px-4 py-3">
                            @if ($product->stockAlerts->isNotEmpty())
                                <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">低库存</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.products.show', $product) }}">查看</a></td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无产品</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $products->links() }}</div>
@endsection
