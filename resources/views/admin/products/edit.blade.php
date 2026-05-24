@extends('layouts.admin')

@section('title', '编辑产品')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">编辑产品：{{ $product->name }}</h1>
    <form method="post" action="{{ route('admin.products.update', $product) }}">
        @include('admin.products._form', ['product' => $product])
    </form>
@endsection
