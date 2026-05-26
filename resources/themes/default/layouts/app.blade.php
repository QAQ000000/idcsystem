<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '客户中心') - IDC System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-50 text-zinc-900">
    <header class="border-b bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="{{ route('client.products.index') }}" class="text-lg font-semibold">IDC System</a>
            <nav class="flex gap-4 text-sm text-zinc-600">
                <a href="{{ route('client.products.index') }}">产品</a>
                <a href="{{ route('client.kb.index') }}">知识库</a>
                <a href="{{ route('client.dashboard') }}">控制台</a>
                <a href="{{ route('client.cart.index') }}">购物车</a>
                <a href="{{ route('client.hosts.index') }}">服务</a>
                <a href="{{ route('client.invoices.index') }}">账单</a>
                <a href="{{ route('client.contracts.index') }}">合同</a>
                <a href="{{ route('client.tickets.index') }}">工单</a>
                @auth('client')
                    <a href="{{ route('client.account.profile') }}">资料</a>
                    <a href="{{ route('client.account.recharge') }}">充值</a>
                    <a href="{{ route('client.affiliate') }}">推介</a>
                    <a href="{{ route('client.account.notifications') }}">通知</a>
                    <a href="{{ route('client.account.security') }}">安全</a>
                @endauth
                @auth('client')
                    <form method="post" action="{{ route('client.logout') }}">
                        @csrf
                        <button>退出</button>
                    </form>
                @else
                    <a href="{{ route('client.login') }}">登录</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-6 py-8">
        @if (session('status'))
            <div class="mb-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
