@extends('layouts.client')

@section('title', '创建工单')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">创建工单</h1>
    <form method="post" action="{{ route('client.tickets.store') }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-4 block text-sm">部门
            <select class="mt-1 w-full rounded border px-3 py-2" name="department_id">
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="mb-4 block text-sm">主题
            <input class="mt-1 w-full rounded border px-3 py-2" name="subject" required>
        </label>
        <label class="mb-4 block text-sm">内容
            <textarea class="mt-1 w-full rounded border px-3 py-2" name="message" rows="5" required></textarea>
        </label>
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">提交</button>
    </form>
@endsection
