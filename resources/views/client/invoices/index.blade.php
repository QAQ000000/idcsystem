@extends('layouts.client')

@section('title', '我的账单')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">我的账单</h1>
    <x-table :rows="$invoices" :columns="['ID' => 'id', '账单号' => 'invoice_number', '状态' => 'status', '总额' => 'total']" route-prefix="client.invoices" />
@endsection
