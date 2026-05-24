@extends('layouts.admin')

@section('title', '产品详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $product->name }}</h1>
    <div class="rounded bg-white p-5 shadow-sm">
        <p>类型：{{ $product->type }}</p>
        <p>服务器模块：{{ $product->server_type ?: '未绑定' }}</p>
        <p>库存：{{ $product->stock_qty }}</p>
        <p>说明：{{ $product->description }}</p>
    </div>
@endsection
