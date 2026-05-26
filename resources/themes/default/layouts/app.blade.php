<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('messages.app.client_center')) - IDC System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-50 text-zinc-900">
    <header class="border-b bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="{{ route('client.products.index') }}" class="text-lg font-semibold">IDC System</a>
            <nav class="flex gap-4 text-sm text-zinc-600">
                <a href="{{ route('client.products.index') }}">{{ __('messages.nav.products') }}</a>
                <a href="{{ route('client.kb.index') }}">{{ __('messages.nav.knowledge_base') }}</a>
                <a href="{{ route('client.dashboard') }}">{{ __('messages.nav.dashboard') }}</a>
                <a href="{{ route('client.cart.index') }}">{{ __('messages.nav.cart') }}</a>
                <a href="{{ route('client.hosts.index') }}">{{ __('messages.nav.hosts') }}</a>
                <a href="{{ route('client.domains.index') }}">域名</a>
                <a href="{{ route('client.ssl.index') }}">SSL</a>
                <a href="{{ route('client.invoices.index') }}">{{ __('messages.nav.invoices') }}</a>
                <a href="{{ route('client.contracts.index') }}">{{ __('messages.nav.contracts') }}</a>
                <a href="{{ route('client.tickets.index') }}">{{ __('messages.nav.tickets') }}</a>
                @auth('client')
                    <a href="{{ route('client.account.profile') }}">{{ __('messages.nav.profile') }}</a>
                    <a href="{{ route('client.account.recharge') }}">{{ __('messages.nav.recharge') }}</a>
                    <a href="{{ route('client.affiliate') }}">{{ __('messages.nav.affiliate') }}</a>
                    <a href="{{ route('client.account.activity') }}">{{ __('messages.nav.activity') }}</a>
                    <a href="{{ route('client.account.notifications') }}">{{ __('messages.nav.notifications') }}</a>
                    <a href="{{ route('client.account.security') }}">{{ __('messages.nav.security') }}</a>
                @endauth
                <form method="get" action="{{ url()->current() }}">
                    <select class="rounded border px-2 py-1 text-xs" name="lang" onchange="this.form.submit()" aria-label="{{ __('messages.language.label') }}">
                        @foreach (config('app.available_locales', ['zh_CN', 'en']) as $locale)
                            <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ __('messages.language.' . $locale) }}</option>
                        @endforeach
                    </select>
                </form>
                @auth('client')
                    <form method="post" action="{{ route('client.logout') }}">
                        @csrf
                        <button>{{ __('messages.nav.logout') }}</button>
                    </form>
                @else
                    <a href="{{ route('client.login') }}">{{ __('messages.nav.login') }}</a>
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
