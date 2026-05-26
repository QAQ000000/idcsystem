@extends('layouts.admin')

@section('title', '编辑税率规则')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">编辑税率规则：{{ $taxRule->name }}</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.tax-rules.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.tax-rules.update', $taxRule) }}">
        @include('admin.tax-rules._form')
    </form>
@endsection
