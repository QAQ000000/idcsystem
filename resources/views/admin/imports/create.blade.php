@extends('layouts.admin')

@section('title', '上传导入文件')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">上传导入文件</h1>
            <p class="mt-1 text-sm text-slate-500">当前类型：{{ $type }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.imports.template', $type) }}">下载 CSV 模板</a>
    </div>

    <form class="rounded bg-white p-6 shadow-sm" method="post" action="{{ route('admin.imports.store', $type) }}" enctype="multipart/form-data">
        @csrf
        <label class="block text-sm">
            <span class="text-slate-600">CSV 文件</span>
            <input class="mt-1 w-full rounded border px-3 py-2" type="file" name="file" accept=".csv,.txt" required>
        </label>
        <div class="mt-6">
            <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">上传并开始导入</button>
        </div>
    </form>
@endsection
