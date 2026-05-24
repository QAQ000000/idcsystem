@extends('layouts.admin')

@section('title', '仪表盘')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">仪表盘</h1>
    <div class="grid gap-4 md:grid-cols-5">
        @foreach ($stats as $label => $value)
            <div class="rounded bg-white p-5 shadow-sm">
                <div class="text-sm text-slate-500">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>
@endsection
