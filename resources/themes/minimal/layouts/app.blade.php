<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '客户中心') - IDC System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-zinc-950">
    <header class="border-b">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-5 py-4">
            <a href="{{ route('client.products.index') }}" class="font-semibold">IDC System</a>
            <nav class="flex gap-4 text-sm text-zinc-600">
                <a href="{{ route('client.products.index') }}">产品</a>
                @auth('client')
                    <a href="{{ route('client.dashboard') }}">控制台</a>
                    <a href="{{ route('client.cart.index') }}">购物车</a>
                    <a href="{{ route('client.hosts.index') }}">服务</a>
                    <a href="{{ route('client.invoices.index') }}">账单</a>
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

    <main class="mx-auto max-w-5xl px-5 py-8">
        @if (session('status'))
            <div class="mb-6 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
