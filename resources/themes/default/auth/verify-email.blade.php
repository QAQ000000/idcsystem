@extends('theme::layouts.app')

@section('title', '验证邮箱')

@section('content')
    <div class="max-w-xl rounded bg-white p-6 shadow-sm">
        <h1 class="text-2xl font-semibold">验证邮箱</h1>
        <p class="mt-4 text-sm text-zinc-600">
            验证邮件已发送至 {{ $client->email }}。请在 24 小时内点击邮件中的链接完成账号激活。
        </p>

        <form method="post" action="{{ route('verification.resend') }}" class="mt-6">
            @csrf
            <button class="rounded bg-zinc-900 px-4 py-2 text-white">重新发送验证邮件</button>
        </form>
    </div>
@endsection
