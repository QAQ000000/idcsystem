@extends('layouts.client')

@section('title', '服务详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $host->product?->name ?: '服务详情' }}</h1>
    <div class="rounded bg-white p-5 shadow-sm">
        <p>状态：{{ $host->status }}</p>
        <p>域名：{{ $host->domain }}</p>
        <p>到期：{{ $host->next_due_date }}</p>
    </div>
@endsection
