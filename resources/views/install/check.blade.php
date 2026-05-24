@extends('install.layout')

@section('title', '环境检查')

@section('content')
    <section class="rounded bg-white p-6 shadow-sm">
        <h2 class="mb-5 text-lg font-semibold">环境检查</h2>
        <div class="divide-y">
            @foreach ($checks as $check)
                <div class="flex items-center justify-between py-3 text-sm">
                    <div>
                        <div class="font-medium">{{ $check['label'] }}</div>
                        <div class="text-slate-500">{{ $check['value'] }}</div>
                    </div>
                    <span class="{{ $check['passed'] ? 'text-emerald-700' : 'text-red-700' }}">
                        {{ $check['passed'] ? '通过' : '失败' }}
                    </span>
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex justify-end">
            <a class="rounded bg-slate-900 px-4 py-2 text-white" href="{{ route('install.database') }}">继续配置数据库</a>
        </div>
    </section>
@endsection
