@extends('layouts.admin')

@section('title', '客户详情')

@section('content')
    <h1 class="mb-4 text-2xl font-semibold">{{ $client->username }}</h1>
    <div class="rounded bg-white p-5 shadow-sm">
        <p>邮箱：{{ $client->email }}</p>
        <p>状态：{{ $client->status }}</p>
        <p>余额：{{ $client->credit }}</p>
    </div>
@endsection
