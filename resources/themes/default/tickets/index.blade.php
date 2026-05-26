@extends('theme::layouts.app')

@section('title', __('messages.tickets.title'))

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">{{ __('messages.tickets.title') }}</h1>
    <x-table :rows="$tickets" :columns="[__('messages.tickets.id') => 'id', __('messages.tickets.number') => 'ticket_number', __('messages.tickets.subject') => 'subject', __('messages.tickets.status') => 'status.name']" route-prefix="client.tickets" />
@endsection
