@extends('layouts.admin')

@section('title', '客户列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">客户列表</h1>
    <x-table :rows="$clients" :columns="['ID' => 'id', '用户名' => 'username', '邮箱' => 'email', '状态' => 'status', '余额' => 'credit']" route-prefix="admin.clients" />
@endsection
