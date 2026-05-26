@extends('theme::layouts.app')

@section('title', __('messages.hosts.title'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('messages.hosts.title') }}</h1>
    <x-table :rows="$hosts" :columns="[__('messages.hosts.id') => 'id', __('messages.hosts.product') => 'product.name', __('messages.hosts.domain') => 'domain', __('messages.hosts.status') => 'status', __('messages.hosts.due_at') => 'next_due_date']" route-prefix="client.hosts" />
@endsection
