@extends('layouts.admin')

@section('title', '产品详情')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $product->name }}</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.products.edit', $product) }}">编辑产品</a>
    </div>

    <div class="rounded bg-white p-5 shadow-sm">
        <p>类型：{{ $product->type }}</p>
        <p>服务器模块：{{ $product->server_type ?: '未绑定' }}</p>
        <p>库存：{{ $product->stock_qty }}</p>
        <p>说明：{{ $product->description }}</p>
    </div>

    <section class="mt-6 rounded bg-white p-6 shadow-sm">
        <h2 class="mb-4 font-semibold">自定义字段</h2>
        <div class="divide-y">
            @forelse ($product->customFields as $field)
                <form method="post" action="{{ route('admin.products.custom-fields.update', [$product, $field]) }}" class="grid gap-3 py-4 md:grid-cols-6">
                    @csrf
                    @method('put')
                    <input class="rounded border px-3 py-2 text-sm" name="field_name" value="{{ old('field_name', $field->field_name) }}" required>
                    <select class="rounded border px-3 py-2 text-sm" name="field_type">
                        @foreach (['text' => '文本', 'textarea' => '多行文本', 'dropdown' => '下拉', 'checkbox' => '勾选', 'password' => '密码'] as $value => $label)
                            <option value="{{ $value }}" @selected($field->field_type === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <input class="rounded border px-3 py-2 text-sm" name="description" value="{{ old('description', $field->description) }}" placeholder="说明">
                    <input class="rounded border px-3 py-2 text-sm" name="options" value="{{ old('options', $field->options) }}" placeholder="选项，每行一个或 JSON">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <label><input type="checkbox" name="required" value="1" @checked($field->required)> 必填</label>
                        <label><input type="checkbox" name="admin_only" value="1" @checked($field->admin_only)> 仅后台</label>
                    </div>
                    <div class="flex gap-2">
                        <input class="w-20 rounded border px-3 py-2 text-sm" name="sort_order" type="number" value="{{ old('sort_order', $field->sort_order) }}">
                        <button class="rounded bg-slate-900 px-3 py-2 text-sm text-white">保存</button>
                    </div>
                </form>
                <form method="post" action="{{ route('admin.products.custom-fields.destroy', [$product, $field]) }}" class="mb-4">
                    @csrf
                    @method('delete')
                    <button class="text-sm text-red-600">删除 {{ $field->field_name }}</button>
                </form>
            @empty
                <p class="py-4 text-sm text-slate-500">暂无自定义字段</p>
            @endforelse
        </div>

        <form method="post" action="{{ route('admin.products.custom-fields.store', $product) }}" class="mt-6 grid gap-3 border-t pt-5 md:grid-cols-6">
            @csrf
            <input class="rounded border px-3 py-2 text-sm" name="field_name" value="{{ old('field_name') }}" placeholder="字段名称" required>
            <select class="rounded border px-3 py-2 text-sm" name="field_type">
                <option value="text">文本</option>
                <option value="textarea">多行文本</option>
                <option value="dropdown">下拉</option>
                <option value="checkbox">勾选</option>
                <option value="password">密码</option>
            </select>
            <input class="rounded border px-3 py-2 text-sm" name="description" value="{{ old('description') }}" placeholder="说明">
            <textarea class="rounded border px-3 py-2 text-sm" name="options" rows="1" placeholder="下拉选项，每行一个">{{ old('options') }}</textarea>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <label><input type="checkbox" name="required" value="1" @checked(old('required'))> 必填</label>
                <label><input type="checkbox" name="admin_only" value="1" @checked(old('admin_only'))> 仅后台</label>
            </div>
            <div class="flex gap-2">
                <input class="w-20 rounded border px-3 py-2 text-sm" name="sort_order" type="number" value="{{ old('sort_order', 0) }}">
                <button class="rounded bg-slate-900 px-3 py-2 text-sm text-white">新增字段</button>
            </div>
        </form>
    </section>
@endsection
