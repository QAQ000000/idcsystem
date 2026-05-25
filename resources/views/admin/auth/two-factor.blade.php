<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台两步验证 - IDC System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-6">
        <form method="post" action="{{ route('admin.login.2fa.verify') }}" class="w-full rounded bg-white p-6 shadow-sm">
            @csrf
            <h1 class="mb-6 text-2xl font-semibold">后台两步验证</h1>
            <label class="mb-4 block text-sm">
                6 位验证码
                <input class="mt-1 w-full rounded border px-3 py-2" name="code" inputmode="numeric" pattern="\d{6}" required autofocus>
            </label>
            @if ($errors->any())
                <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
            @endif
            <button class="w-full rounded bg-slate-900 px-4 py-2 text-white">验证并登录</button>
        </form>
    </main>
</body>
</html>
