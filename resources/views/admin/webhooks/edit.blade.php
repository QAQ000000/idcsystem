@extends('layouts.admin')

@section('title', '编辑 Webhook')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">编辑 Webhook</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.webhooks.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.webhooks.update', $webhook) }}">
        @csrf
        @method('PUT')
        @include('admin.webhooks._form')
    </form>
@endsection
