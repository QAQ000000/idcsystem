@extends('layouts.admin')

@section('title', '客户列表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">客户列表</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.export.clients', request()->query()) }}">导出 CSV</a>
    </div>
    <x-table :rows="$clients" :columns="['ID' => 'id', '用户名' => 'username', '邮箱' => 'email', '状态' => 'status', '余额' => 'credit']" route-prefix="admin.clients" />
@endsection
