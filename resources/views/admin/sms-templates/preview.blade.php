@extends('layouts.admin')

@section('title', '预览短信模板')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">预览短信模板</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $smsTemplate->name }}</p>
        </div>
        <div class="flex gap-2">
            <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.sms-templates.edit', $smsTemplate) }}">编辑模板</a>
            <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.sms-templates.index') }}">返回列表</a>
        </div>
    </div>

    @if (session('error'))
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
        <section class="rounded bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">短信内容</h2>
            <div class="rounded border bg-slate-50 p-5 text-sm leading-7">
                {{ $renderedContent }}
            </div>
        </section>

        <aside class="space-y-6">
            <form method="post" action="{{ route('admin.sms-templates.test', $smsTemplate) }}" class="rounded bg-white p-5 text-sm shadow-sm">
                @csrf
                <h2 class="mb-4 font-semibold">发送测试短信</h2>
                <label class="block">
                    手机号
                    <input class="mt-1 w-full rounded border px-3 py-2" name="phone" required>
                </label>
                @if ($errors->any())
                    <div class="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-red-700">{{ $errors->first() }}</div>
                @endif
                <button class="mt-4 w-full rounded bg-slate-900 px-4 py-2 text-white">发送测试</button>
            </form>

            <section class="rounded bg-white p-5 text-sm shadow-sm">
                <h2 class="mb-3 font-semibold">测试变量</h2>
                <dl class="space-y-2">
                    @foreach ($variables as $key => $value)
                        <div>
                            <dt class="text-slate-500">{{ $key }}</dt>
                            <dd class="break-all">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </aside>
    </div>
@endsection
