@extends('layouts.admin')

@section('title', '新建 Webhook')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">新建 Webhook</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.webhooks.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.webhooks.store') }}">
        @include('admin.webhooks._form')
    </form>
@endsection
