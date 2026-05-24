@extends('layouts.client')

@section('title', '控制台')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">控制台</h1>
    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded bg-white p-5 shadow-sm">服务：{{ $hosts->count() }}</div>
        <div class="rounded bg-white p-5 shadow-sm">账单：{{ $invoices->count() }}</div>
        <div class="rounded bg-white p-5 shadow-sm">工单：{{ $tickets->count() }}</div>
    </div>
@endsection
