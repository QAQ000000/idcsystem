@extends('layouts.admin')

@section('title', '分销管理')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">分销管理</h1>

    <form method="get" class="mb-6 grid gap-4 rounded bg-white p-5 shadow-sm md:grid-cols-4">
        <label class="block text-sm">
            关键词
            <input class="mt-1 w-full rounded border px-3 py-2" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="客户/邮箱/推介码">
        </label>
        <label class="block text-sm">
            推介账户状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="status">
                <option value="">全部</option>
                @foreach (['active' => '正常', 'inactive' => '停用'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            佣金状态
            <select class="mt-1 w-full rounded border px-3 py-2" name="commission_status">
                <option value="">全部</option>
                @foreach (['pending' => '待审核', 'approved' => '已审核', 'paid' => '已发放', 'cancelled' => '已取消'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['commission_status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            <a class="rounded border px-4 py-2" href="{{ route('admin.affiliates.index') }}">重置</a>
        </div>
    </form>

    <section class="mb-8 overflow-hidden rounded bg-white shadow-sm">
        <div class="border-b px-5 py-4 font-semibold">推介账户</div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">客户</th>
                    <th class="px-4 py-3">推介码</th>
                    <th class="px-4 py-3">人数</th>
                    <th class="px-4 py-3">可发放</th>
                    <th class="px-4 py-3">已发放</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($affiliates as $affiliate)
                    <tr>
                        <td class="px-4 py-3">{{ $affiliate->client?->username ?: '-' }}<div class="text-xs text-slate-500">{{ $affiliate->client?->email }}</div></td>
                        <td class="px-4 py-3">{{ $affiliate->code }}</td>
                        <td class="px-4 py-3">{{ $affiliate->referral_count }}</td>
                        <td class="px-4 py-3">{{ $affiliate->balance }}</td>
                        <td class="px-4 py-3">{{ $affiliate->withdrawn }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('admin.affiliates.update', $affiliate) }}">
                                @csrf
                                @method('PUT')
                                <select class="rounded border px-2 py-1" name="status" onchange="this.form.submit()">
                                    <option value="active" @selected($affiliate->status === 'active')>正常</option>
                                    <option value="inactive" @selected($affiliate->status === 'inactive')>停用</option>
                                </select>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('admin.affiliates.payout', $affiliate) }}" class="flex gap-2">
                                @csrf
                                <input class="w-24 rounded border px-2 py-1" name="amount" type="number" min="0.01" step="0.01" max="{{ $affiliate->balance }}" value="{{ $affiliate->balance }}">
                                <button class="rounded bg-slate-900 px-3 py-1 text-white">发放</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无推介账户</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3">{{ $affiliates->links() }}</div>
    </section>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <div class="border-b px-5 py-4 font-semibold">佣金记录</div>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">推介人</th>
                    <th class="px-4 py-3">推荐客户</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">账单</th>
                    <th class="px-4 py-3">金额</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($commissions as $commission)
                    <tr>
                        <td class="px-4 py-3">{{ $commission->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">{{ $commission->affiliate?->client?->username ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $commission->referredClient?->username ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $commission->type === 'signup' ? '注册' : '付款' }}</td>
                        <td class="px-4 py-3">{{ $commission->invoice?->invoice_number ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $commission->amount }}</td>
                        <td class="px-4 py-3">{{ $commission->status }}</td>
                        <td class="px-4 py-3">
                            @if ($commission->status === 'pending')
                                <form method="post" action="{{ route('admin.affiliate-commissions.approve', $commission) }}">
                                    @csrf
                                    <button class="rounded bg-emerald-700 px-3 py-1 text-white">审核</button>
                                </form>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="8">暂无佣金记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3">{{ $commissions->links() }}</div>
    </section>
@endsection
