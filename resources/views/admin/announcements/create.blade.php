@extends('layouts.admin')

@section('title', '新建公告')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">新建公告</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.announcements.index') }}">返回列表</a>
    </div>

    <form method="post" action="{{ route('admin.announcements.store') }}">
        @include('admin.announcements._form')
    </form>
@endsection
