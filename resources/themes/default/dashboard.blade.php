@extends('theme::layouts.app')

@section('title', '控制台')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">控制台</h1>

    <div class="grid gap-4 md:grid-cols-5">
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">可用额度</div>
            <div class="mt-2 text-xl font-semibold">{{ number_format($client->availableCredit(), 2, '.', '') }}</div>
            <div class="mt-1 text-xs {{ (float) $client->credit < 0 ? 'text-red-600' : 'text-slate-500' }}">
                @if ((float) $client->credit < 0)
                    当前欠款：{{ number_format(abs((float) $client->credit), 2, '.', '') }}
                @else
                    余额：{{ $client->credit }} / 信用额度：{{ $client->credit_limit }}
                @endif
            </div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">本月消费</div>
            <div class="mt-2 text-xl font-semibold">{{ number_format((float) $monthlySpend, 2, '.', '') }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">服务数</div>
            <div class="mt-2 text-xl font-semibold">{{ $hosts->count() }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">未付账单</div>
            <div class="mt-2 text-xl font-semibold">{{ $unpaidInvoices->count() }}</div>
        </div>
        <div class="rounded bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">工单数</div>
            <div class="mt-2 text-xl font-semibold">{{ $tickets->count() }}</div>
        </div>
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-2">
        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="font-semibold">即将到期的服务</h2>
            <div class="mt-4 divide-y">
                @forelse ($upcomingRenewals as $host)
                    @php($urgent = $host->next_due_date && $host->next_due_date->lte(now()->addDays(7)))
                    <div class="py-3 text-sm">
                        <div class="font-medium">{{ $host->domain ?: $host->product?->name }}</div>
                        <div class="mt-1 {{ $urgent ? 'text-red-600' : 'text-slate-600' }}">到期：{{ $host->next_due_date?->format('Y-m-d') ?: '-' }}</div>
                        <a class="mt-2 inline-block text-blue-600" href="{{ route('client.hosts.show', $host) }}">立即续费</a>
                    </div>
                @empty
                    <div class="py-4 text-sm text-slate-500">暂无即将到期服务</div>
                @endforelse
            </div>
        </section>

        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="font-semibold">未付账单</h2>
            <div class="mt-4 divide-y">
                @forelse ($unpaidInvoices as $invoice)
                    @php($urgent = $invoice->due_date && $invoice->due_date->lte(now()->addDays(7)))
                    <div class="py-3 text-sm">
                        <div class="font-medium">{{ $invoice->invoice_number }}</div>
                        <div class="mt-1 {{ $urgent ? 'text-red-600' : 'text-slate-600' }}">到期：{{ $invoice->due_date?->format('Y-m-d') ?: '-' }} / 金额：{{ $invoice->total }}</div>
                        <a class="mt-2 inline-block text-blue-600" href="{{ route('client.invoices.show', $invoice) }}">立即支付</a>
                    </div>
                @empty
                    <div class="py-4 text-sm text-slate-500">暂无未付账单</div>
                @endforelse
            </div>
        </section>
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-2">
        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="font-semibold">最近余额变动</h2>
            <div class="mt-4 divide-y">
                @forelse ($recentCredits as $credit)
                    <div class="py-3 text-sm">
                        <div class="{{ $credit->type === 'deduct' ? 'text-red-600' : 'text-emerald-600' }}">
                            {{ $credit->type === 'deduct' ? '-' : '+' }}{{ number_format((float) $credit->amount, 2, '.', '') }}
                        </div>
                        <div class="mt-1 text-slate-600">{{ $credit->description }} / {{ $credit->created_at?->format('Y-m-d H:i') }}</div>
                    </div>
                @empty
                    <div class="py-4 text-sm text-slate-500">暂无余额流水</div>
                @endforelse
            </div>
        </section>

        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="font-semibold">公告提醒</h2>
            <div class="mt-4 divide-y">
                @forelse ($announcements as $announcement)
                    <div class="py-3 text-sm">
                        <div class="font-medium">{{ $announcement->title }}</div>
                        <div class="mt-1 whitespace-pre-line text-slate-600">{{ $announcement->content }}</div>
                    </div>
                @empty
                    <div class="py-4 text-sm text-slate-500">暂无公告</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
