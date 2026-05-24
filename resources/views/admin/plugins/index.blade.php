@extends('layouts.admin')

@section('title', '插件管理')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">插件管理</h1>
    @foreach ($pluginScans as $type => $scan)
        <section class="mb-6 rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">可安装{{ ['gateway' => '支付', 'email' => '邮件', 'sms' => '短信'][$type] ?? $type }}插件</h2>
            <div class="flex flex-wrap gap-3">
                @forelse ($scan as $plugin)
                    <form method="post" action="{{ route('admin.plugins.install') }}" class="rounded border px-4 py-3 text-sm">
                        @csrf
                        <input type="hidden" name="type" value="{{ $plugin['type'] ?? $type }}">
                        <input type="hidden" name="name" value="{{ $plugin['name'] }}">
                        <div class="font-medium">{{ $plugin['title'] ?? $plugin['name'] }}</div>
                        <div class="text-slate-500">{{ $plugin['description'] ?? '' }}</div>
                        <button class="mt-3 rounded bg-slate-900 px-3 py-1 text-white">{{ ($plugin['installed'] ?? false) ? '重新安装' : '安装' }}</button>
                    </form>
                @empty
                    <p class="text-sm text-slate-500">未扫描到插件。</p>
                @endforelse
            </div>
        </section>
    @endforeach

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">版本</th>
                    <th class="px-4 py-3">描述</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($plugins as $plugin)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $plugin->title ?: $plugin->name }}</div>
                            <div class="text-slate-500">{{ $plugin->name }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $plugin->type }}</td>
                        <td class="px-4 py-3">{{ $plugin->version }}</td>
                        <td class="px-4 py-3">{{ $plugin->description }}</td>
                        <td class="px-4 py-3">{{ $plugin->status ? '启用' : '禁用' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <a class="text-blue-600" href="{{ route('admin.plugins.config', $plugin->name) }}">配置</a>
                                @if ($plugin->status)
                                    <form method="post" action="{{ route('admin.plugins.disable', $plugin->name) }}">
                                        @csrf
                                        <button class="text-red-600">禁用</button>
                                    </form>
                                @else
                                    <form method="post" action="{{ route('admin.plugins.enable', $plugin->name) }}">
                                        @csrf
                                        <button class="text-emerald-700">启用</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="6">暂无插件</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $plugins->links() }}</div>
@endsection
