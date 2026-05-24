@extends('layouts.admin')

@section('title', '订单列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">订单列表</h1>
    <x-table :rows="$orders" :columns="['ID' => 'id', '订单号' => 'order_number', '状态' => 'status', '金额' => 'amount', '客户' => 'client.username']" route-prefix="admin.orders" />
@endsection
