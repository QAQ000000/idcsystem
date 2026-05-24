@extends('layouts.admin')

@section('title', '账单列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">账单列表</h1>
    <x-table :rows="$invoices" :columns="['ID' => 'id', '账单号' => 'invoice_number', '状态' => 'status', '总额' => 'total', '客户' => 'client.username']" route-prefix="admin.invoices" />
@endsection
