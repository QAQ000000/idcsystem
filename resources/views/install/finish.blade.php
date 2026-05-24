@extends('install.layout')

@section('title', '安装完成')

@section('content')
    <section class="rounded bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold">安装完成</h2>
        <p class="mt-3 text-sm text-slate-600">系统已经完成初始化，后续访问安装页会自动跳转到后台登录页。</p>
        <a class="mt-6 inline-block rounded bg-slate-900 px-4 py-2 text-white" href="{{ route('admin.login') }}">进入后台登录</a>
    </section>
@endsection
