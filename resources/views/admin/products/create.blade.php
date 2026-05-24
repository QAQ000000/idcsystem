@extends('layouts.admin')

@section('title', '创建产品')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">创建产品</h1>
    <form method="post" action="{{ route('admin.products.store') }}">
        @include('admin.products._form')
    </form>
@endsection
