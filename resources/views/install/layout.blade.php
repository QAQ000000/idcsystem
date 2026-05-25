<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '安装向导') - IDC System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto max-w-3xl px-6 py-10">
        <div class="mb-8">
            <h1 class="text-2xl font-semibold">IDC System 安装向导</h1>
            <p class="mt-2 text-sm text-slate-600">按步骤完成运行环境、数据库和管理员初始化。</p>
        </div>

        @if (session('status'))
            <div class="mb-6 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
    <script>
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.dataset.preventDoubleSubmit) {
                return;
            }

            const button = form.querySelector('[data-submit-button]');
            if (!button) {
                return;
            }

            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = button.dataset.loadingText || '处理中...';
            button.classList.add('cursor-not-allowed', 'opacity-70');
        });
    </script>
</body>
</html>
