<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '管理后台') - IDC System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="min-h-screen">
        <header class="border-b bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-semibold">IDC 管理后台</a>
                <nav class="flex gap-4 text-sm text-slate-600">
                    <a href="{{ route('admin.clients.index') }}">客户</a>
                    <a href="{{ route('admin.products.index') }}">产品</a>
                    <a href="{{ route('admin.orders.index') }}">订单</a>
                    <a href="{{ route('admin.invoices.index') }}">账单</a>
                    <a href="{{ route('admin.tickets.index') }}">工单</a>
                    <a href="{{ route('admin.plugins.index') }}">插件</a>
                    <a href="{{ route('admin.settings.index') }}">设置</a>
                    <form method="post" action="{{ route('admin.logout') }}">
                        @csrf
                        <button>退出</button>
                    </form>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-8">
            @if (session('status'))
                <div class="mb-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            @yield('content')
        </main>
    </div>
</body>
</html>
