@extends('layouts.client')

@section('title', '我的服务')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">我的服务</h1>
    <x-table :rows="$hosts" :columns="['ID' => 'id', '产品' => 'product.name', '域名' => 'domain', '状态' => 'status', '到期' => 'next_due_date']" route-prefix="client.hosts" />
@endsection
