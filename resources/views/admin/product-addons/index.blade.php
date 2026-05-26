@extends('layouts.admin')

@section('title', '产品附加项')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">{{ $product->name }} 附加项</h1>
            <p class="mt-1 text-sm text-slate-500">维护客户下单时可选的额外服务。</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.products.index') }}">返回产品</a>
    </div>

    <section class="mb-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">新建附加项</h2>
        <form method="post" action="{{ route('admin.products.addons.store', $product) }}" class="grid gap-4 md:grid-cols-3">
            @csrf
            @include('admin.product-addons._fields', ['addon' => new \App\Modules\Product\Models\ProductAddon(['active' => true, 'billing_cycle' => 'recurring'])])
            <div class="md:col-span-3">
                <button class="rounded bg-slate-900 px-4 py-2 text-white">保存附加项</button>
            </div>
        </form>
    </section>

    <section class="rounded bg-white shadow-sm">
        <div class="divide-y divide-slate-100">
            @forelse ($addons as $addon)
                <form method="post" action="{{ route('admin.products.addons.update', [$product, $addon]) }}" class="grid gap-4 p-5 md:grid-cols-3">
                    @csrf
                    @method('PUT')
                    @include('admin.product-addons._fields', ['addon' => $addon])
                    <div class="flex items-center gap-3 md:col-span-3">
                        <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">保存</button>
                        <button form="delete-addon-{{ $addon->id }}" class="text-sm text-red-600" onclick="return confirm('确定删除该附加项？')">删除</button>
                    </div>
                </form>
                <form id="delete-addon-{{ $addon->id }}" method="post" action="{{ route('admin.products.addons.destroy', [$product, $addon]) }}">
                    @csrf
                    @method('DELETE')
                </form>
            @empty
                <div class="p-8 text-center text-slate-500">暂无附加项</div>
            @endforelse
        </div>
    </section>
@endsection
