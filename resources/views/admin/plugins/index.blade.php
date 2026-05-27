@extends('layouts.admin')

@section('title', '插件管理')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">插件管理</h1>
    <section class="mb-6 rounded bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">插件市场</h2>
            <form method="get" action="{{ route('admin.plugins.index') }}" class="flex flex-wrap gap-2 text-sm">
                <input
                    name="search"
                    value="{{ $marketplaceFilters['search'] ?? '' }}"
                    class="rounded border px-3 py-2"
                    placeholder="搜索插件">
                <select name="type" class="rounded border px-3 py-2">
                    <option value="">全部类型</option>
                    @foreach (['gateway' => '支付', 'email' => '邮件', 'sms' => '短信', 'server' => '服务器', 'oauth' => 'OAuth', 'captcha' => '验证码', 'certification' => '实名认证'] as $type => $label)
                        <option value="{{ $type }}" @selected(($marketplaceFilters['type'] ?? '') === $type)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="sort" class="rounded border px-3 py-2">
                    @foreach (['title' => '名称', 'popular' => '下载量', 'rating' => '评分', 'newest' => '最新'] as $sort => $label)
                        <option value="{{ $sort }}" @selected(($marketplaceFilters['sort'] ?? 'title') === $sort)>{{ $label }}</option>
                    @endforeach
                </select>
                <label class="inline-flex items-center gap-2 rounded border px-3 py-2">
                    <input type="checkbox" name="verified" value="1" @checked(($marketplaceFilters['verified'] ?? null) === true || ($marketplaceFilters['verified'] ?? null) === '1' || ($marketplaceFilters['verified'] ?? null) === 1)>
                    只看已认证
                </label>
                <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
            </form>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($marketplacePlugins as $marketplacePlugin)
                @php($installedPlugin = $marketplacePlugin->installedPlugin())
                <article class="rounded border p-4 text-sm">
                    <div class="mb-2 flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium">{{ $marketplacePlugin->title }}</div>
                            <div class="text-slate-500">{{ $marketplacePlugin->name }} · {{ $marketplacePlugin->type }}</div>
                        </div>
                        @if ($marketplacePlugin->is_verified)
                            <span class="rounded bg-emerald-50 px-2 py-1 text-xs text-emerald-700">已认证</span>
                        @endif
                    </div>
                    <p class="min-h-10 text-slate-600">{{ $marketplacePlugin->description ?: '暂无描述' }}</p>
                    <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-500">
                        <span>v{{ $marketplacePlugin->version }}</span>
                        <span>{{ $marketplacePlugin->price > 0 ? '¥' . $marketplacePlugin->price : '免费' }}</span>
                        <span>{{ number_format((float) $marketplacePlugin->rating, 1) }} 分</span>
                        <span>{{ $marketplacePlugin->downloads_count }} 次安装</span>
                    </div>
                    @if (!empty($marketplacePlugin->requirements))
                        <div class="mt-3 rounded bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            要求：
                            @foreach ($marketplacePlugin->requirements as $key => $value)
                                <span>{{ $key }}={{ is_array($value) ? implode(',', $value) : $value }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-4 flex items-center justify-between">
                        <span class="text-xs text-slate-500">{{ $installedPlugin ? '已安装' : '未安装' }}</span>
                        @if ($installedPlugin)
                            <form method="post" action="{{ route('admin.plugins.uninstall', $installedPlugin->name) }}">
                                @csrf
                                <button class="text-red-600">卸载</button>
                            </form>
                        @else
                            <form method="post" action="{{ route('admin.plugins.marketplace.install', $marketplacePlugin) }}">
                                @csrf
                                <button class="rounded bg-slate-900 px-3 py-1 text-white">安装</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <p class="text-sm text-slate-500">暂无市场插件。</p>
            @endforelse
        </div>

        <div class="mt-4">{{ $marketplacePlugins->links() }}</div>
    </section>

    @foreach ($pluginScans as $type => $scan)
        <section class="mb-6 rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">可安装{{ ['gateway' => '支付', 'email' => '邮件', 'sms' => '短信', 'server' => '服务器模块'][$type] ?? $type }}插件</h2>
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
