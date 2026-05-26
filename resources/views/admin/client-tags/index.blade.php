@extends('layouts.admin')

@section('title', '客户标签')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">客户标签</h1>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">创建标签</h2>
            <form method="post" action="{{ route('admin.client-tags.store') }}" class="grid gap-4">
                @csrf
                <label class="text-sm">
                    名称
                    <input class="mt-1 w-full rounded border px-3 py-2" name="name" required maxlength="100">
                </label>
                <label class="text-sm">
                    标识
                    <input class="mt-1 w-full rounded border px-3 py-2" name="slug" maxlength="100" placeholder="vip">
                </label>
                <label class="text-sm">
                    颜色
                    <input class="mt-1 h-10 w-20 rounded border px-1 py-1" name="color" type="color" value="#3B82F6" required>
                </label>
                <label class="text-sm">
                    描述
                    <textarea class="mt-1 w-full rounded border px-3 py-2" name="description" rows="3" maxlength="1000"></textarea>
                </label>
                <button class="w-fit rounded bg-slate-900 px-4 py-2 text-white">保存标签</button>
            </form>
        </section>

        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">创建自动规则</h2>
            <form method="post" action="{{ route('admin.tag-auto-rules.store') }}" class="grid gap-4">
                @csrf
                <label class="text-sm">
                    标签
                    <select class="mt-1 w-full rounded border px-3 py-2" name="client_tag_id" required>
                        @foreach ($tags as $tag)
                            <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="text-sm">
                        条件
                        <select class="mt-1 w-full rounded border px-3 py-2" name="condition_type">
                            <option value="total_spent">累计消费</option>
                            <option value="order_count">订单数</option>
                            <option value="overdue_count">逾期账单数</option>
                            <option value="credit_balance">余额</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        运算
                        <select class="mt-1 w-full rounded border px-3 py-2" name="operator">
                            @foreach (['>', '>=', '<', '<=', '='] as $operator)
                                <option value="{{ $operator }}">{{ $operator }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">
                        阈值
                        <input class="mt-1 w-full rounded border px-3 py-2" name="threshold" type="number" min="0" step="0.01" required>
                    </label>
                </div>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" value="1" checked>
                    启用
                </label>
                <button class="w-fit rounded bg-slate-900 px-4 py-2 text-white">保存规则</button>
            </form>
        </section>
    </div>

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">标签列表</h2>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">标签</th>
                    <th class="px-4 py-3">标识</th>
                    <th class="px-4 py-3">客户数</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($tags as $tag)
                    <tr>
                        <td class="px-4 py-3"><span class="rounded px-2 py-0.5 text-white" style="background-color: {{ $tag->color }}">{{ $tag->name }}</span></td>
                        <td class="px-4 py-3">{{ $tag->slug }}</td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.client-tags.clients', $tag) }}">{{ $tag->clients_count }}</a></td>
                        <td class="px-4 py-3">{{ $tag->system ? '系统' : '自定义' }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('admin.client-tags.update', $tag) }}" class="grid gap-2 md:grid-cols-[1fr_1fr_auto_auto]">
                                @csrf
                                @method('PUT')
                                <input class="rounded border px-2 py-1" name="name" value="{{ $tag->name }}" required>
                                <input class="rounded border px-2 py-1" name="slug" value="{{ $tag->slug }}" required>
                                <input class="h-9 w-16 rounded border px-1 py-1" name="color" type="color" value="{{ $tag->color }}" required>
                                <button class="text-blue-600">更新</button>
                            </form>
                            @if (!$tag->system)
                                <form method="post" action="{{ route('admin.client-tags.destroy', $tag) }}" class="mt-2">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-red-700">删除</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="mt-4">{{ $tags->links() }}</div>
    </section>

    <section class="mt-6 rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 font-semibold">自动规则</h2>
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">标签</th>
                    <th class="px-4 py-3">条件</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rules as $rule)
                    <tr>
                        <td class="px-4 py-3">{{ $rule->tag?->name }}</td>
                        <td class="px-4 py-3">{{ $rule->condition_type }} {{ $rule->operator }} {{ $rule->threshold }}</td>
                        <td class="px-4 py-3">{{ $rule->active ? '启用' : '停用' }}</td>
                        <td class="px-4 py-3">
                            <form method="post" action="{{ route('admin.tag-auto-rules.update', $rule) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="client_tag_id" value="{{ $rule->client_tag_id }}">
                                <input type="hidden" name="condition_type" value="{{ $rule->condition_type }}">
                                <input type="hidden" name="operator" value="{{ $rule->operator }}">
                                <input class="w-28 rounded border px-2 py-1" name="threshold" type="number" min="0" step="0.01" value="{{ $rule->threshold }}">
                                <input type="hidden" name="active" value="0">
                                <label class="inline-flex items-center gap-1"><input type="checkbox" name="active" value="1" @checked($rule->active)> 启用</label>
                                <button class="text-blue-600">更新</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="4">暂无规则</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $rules->links() }}</div>
    </section>
@endsection
