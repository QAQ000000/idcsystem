@extends('layouts.admin')

@section('title', '编辑优惠码')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">编辑优惠码：{{ $promoCode->code }}</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.promo-codes.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.promo-codes.update', $promoCode) }}">
        @include('admin.promo-codes._form')
    </form>
@endsection
