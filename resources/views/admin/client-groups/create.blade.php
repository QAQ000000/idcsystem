@extends('layouts.admin')

@section('title', '新建客户分组')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">新建客户分组</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.client-groups.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.client-groups.store') }}">
        @include('admin.client-groups._form')
    </form>
@endsection
