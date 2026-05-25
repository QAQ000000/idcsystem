@extends('layouts.admin')

@section('title', '编辑合同模板')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">编辑合同模板</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.contract-templates.index') }}">返回列表</a>
    </div>

    @include('admin.contract-templates.form', [
        'action' => route('admin.contract-templates.update', $template),
        'method' => 'put',
        'template' => $template,
    ])
@endsection
