@extends('theme::layouts.app')

@section('title', __('messages.invoices.title'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('messages.invoices.title') }}</h1>
    <x-table :rows="$invoices" :columns="[__('messages.invoices.id') => 'id', __('messages.invoices.number') => 'invoice_number', __('messages.invoices.status') => 'status', __('messages.invoices.total') => 'total']" route-prefix="client.invoices" />
@endsection
