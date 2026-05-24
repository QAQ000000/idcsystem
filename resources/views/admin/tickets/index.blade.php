@extends('layouts.admin')

@section('title', '工单列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">工单列表</h1>
    <x-table :rows="$tickets" :columns="['ID' => 'id', '工单号' => 'ticket_number', '主题' => 'subject', '客户' => 'client.username', '状态' => 'status.name']" route-prefix="admin.tickets" />
@endsection
