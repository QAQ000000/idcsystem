@extends('layouts.admin')

@section('title', '客户列表')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">客户列表</h1>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.export.clients', request()->query()) }}">导出 CSV</a>
    </div>

    <form method="get" action="{{ route('admin.clients.index') }}" class="mb-6 grid gap-4 rounded bg-white p-5 text-sm shadow-sm md:grid-cols-5">
        <label>
            关键词
            <input class="mt-1 w-full rounded border px-3 py-2" name="keyword" value="{{ $keyword }}">
        </label>
        <label>
            标签
            <select class="mt-1 w-full rounded border px-3 py-2" name="tag_id">
                <option value="">全部标签</option>
                @foreach ($tags as $tag)
                    <option value="{{ $tag->id }}" @selected((int) $tagId === (int) $tag->id)>{{ $tag->name }}</option>
                @endforeach
            </select>
        </label>
        <label>
            信用等级
            <select class="mt-1 w-full rounded border px-3 py-2" name="credit_level">
                <option value="">全部等级</option>
                @foreach (['Excellent' => '优秀', 'Good' => '良好', 'Fair' => '一般', 'Poor' => '较差'] as $level => $label)
                    <option value="{{ $level }}" @selected($creditLevel === $level)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>
            排序
            <select class="mt-1 w-full rounded border px-3 py-2" name="sort">
                <option value="">默认</option>
                <option value="credit_score_desc" @selected($sort === 'credit_score_desc')>信用分从高到低</option>
                <option value="credit_score_asc" @selected($sort === 'credit_score_asc')>信用分从低到高</option>
            </select>
        </label>
        <div class="flex items-end">
            <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
        </div>
    </form>

    <form id="client-bulk-form" method="post" action="{{ route('admin.clients.bulk-action') }}" class="mb-6 rounded bg-white p-5 shadow-sm">
        @csrf
        <div class="grid gap-4 md:grid-cols-4">
            <label class="block text-sm">
                批量操作
                <select class="mt-1 w-full rounded border px-3 py-2" name="action" required>
                    <option value="suspend">批量暂停</option>
                    <option value="activate">批量激活</option>
                    <option value="add_credit">批量充值</option>
                    <option value="send_email">批量发邮件</option>
                </select>
            </label>
            <label class="block text-sm">
                充值金额
                <input class="mt-1 w-full rounded border px-3 py-2" type="number" name="amount" min="0.01" step="0.01">
            </label>
            <label class="block text-sm">
                邮件主题
                <input class="mt-1 w-full rounded border px-3 py-2" name="subject" maxlength="200">
            </label>
            <label class="block text-sm">
                操作说明
                <input class="mt-1 w-full rounded border px-3 py-2" name="description" maxlength="255" placeholder="批量充值说明">
            </label>
            <label class="block text-sm md:col-span-4">
                邮件内容
                <textarea class="mt-1 w-full rounded border px-3 py-2" name="body" rows="3" maxlength="5000"></textarea>
            </label>
        </div>
    </form>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3"><input type="checkbox" onclick="document.querySelectorAll('[data-client-bulk]').forEach(el => el.checked = this.checked)"></th>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">用户名</th>
                    <th class="px-4 py-3">邮箱</th>
                    <th class="px-4 py-3">标签</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">余额</th>
                    <th class="px-4 py-3">信用等级</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($clients as $client)
                    <tr>
                        <td class="px-4 py-3"><input data-client-bulk form="client-bulk-form" type="checkbox" name="client_ids[]" value="{{ $client->id }}"></td>
                        <td class="px-4 py-3">{{ $client->id }}</td>
                        <td class="px-4 py-3">{{ $client->username }}</td>
                        <td class="px-4 py-3">{{ $client->email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($client->tags as $tag)
                                    <span class="rounded px-2 py-0.5 text-xs text-white" style="background-color: {{ $tag->color }}">{{ $tag->name }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $client->status }}</td>
                        <td class="px-4 py-3">{{ $client->credit }}</td>
                        <td class="px-4 py-3">{{ $client->credit_score }} / {{ $client->credit_level }}</td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.clients.show', $client) }}">查看</a></td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="9">暂无客户</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center justify-between">
        <button form="client-bulk-form" class="rounded bg-slate-900 px-4 py-2 text-white">执行批量操作</button>
        <div>{{ $clients->links() }}</div>
    </div>
@endsection
