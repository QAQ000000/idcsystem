@extends('theme::layouts.app')

@section('title', '我的工单')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">我的工单</h1>
    <x-table :rows="$tickets" :columns="['ID' => 'id', '工单号' => 'ticket_number', '主题' => 'subject', '状态' => 'status.name']" route-prefix="client.tickets" />
@endsection
