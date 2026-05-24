@extends('layouts.admin')

@section('title', '产品列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">产品列表</h1>
    <x-table :rows="$products" :columns="['ID' => 'id', '名称' => 'name', '类型' => 'type', '隐藏' => 'hidden', '库存' => 'stock_qty']" route-prefix="admin.products" />
@endsection
