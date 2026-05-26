@extends('theme::layouts.app')

@section('title', '推介排行榜')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">推介排行榜</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('client.affiliate') }}">返回推介中心</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        @foreach ([
            '佣金排行' => [$commissionLeaders, fn ($item) => number_format((float) $item->balance + (float) $item->withdrawn, 2)],
            '推介数排行' => [$referralLeaders, fn ($item) => (string) $item->total_signups],
            '点击数排行' => [$clickLeaders, fn ($item) => (string) $item->total_clicks],
        ] as $title => [$leaders, $value])
            <section class="rounded bg-white p-5 shadow-sm">
                <h2 class="mb-4 font-semibold">{{ $title }}</h2>
                <ol class="space-y-3 text-sm">
                    @forelse ($leaders as $index => $leader)
                        <li class="flex items-center justify-between gap-3">
                            <span>
                                <span class="mr-2 inline-flex w-6 justify-center rounded bg-slate-100 px-2 py-0.5">{{ $index + 1 }}</span>
                                {{ $leader->client?->username ?: '-' }}
                            </span>
                            <span class="font-semibold">{{ $value($leader) }}</span>
                        </li>
                    @empty
                        <li class="text-slate-500">暂无数据</li>
                    @endforelse
                </ol>
            </section>
        @endforeach
    </div>
@endsection
