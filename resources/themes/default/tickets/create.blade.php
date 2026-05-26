@extends('theme::layouts.app')

@section('title', '创建工单')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">创建工单</h1>
    <form method="post" action="{{ route('client.tickets.store') }}" class="rounded bg-white p-6 shadow-sm">
        @csrf
        <label class="mb-4 block text-sm">部门
            <select class="mt-1 w-full rounded border px-3 py-2" name="department_id">
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="mb-4 block text-sm">主题
            <input class="mt-1 w-full rounded border px-3 py-2" name="subject" data-kb-subject required>
        </label>
        <section class="mb-4 hidden rounded border border-blue-100 bg-blue-50 p-4 text-sm" data-kb-recommendations>
            <div class="font-medium text-blue-900">可能有帮助的知识库文章</div>
            <div class="mt-2 space-y-2" data-kb-results></div>
        </section>
        <label class="mb-4 block text-sm">内容
            <textarea class="mt-1 w-full rounded border px-3 py-2" name="message" rows="5" required></textarea>
        </label>
        <button class="rounded bg-zinc-900 px-4 py-2 text-white">提交</button>
    </form>

    <script>
        (() => {
            const input = document.querySelector('[data-kb-subject]');
            const panel = document.querySelector('[data-kb-recommendations]');
            const results = document.querySelector('[data-kb-results]');
            if (!input || !panel || !results) return;

            let timer = null;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                const query = input.value.trim();
                if (query.length < 2) {
                    panel.classList.add('hidden');
                    results.innerHTML = '';
                    return;
                }

                timer = setTimeout(async () => {
                    const response = await fetch(`{{ route('client.kb.search') }}?q=${encodeURIComponent(query)}`, {
                        headers: { Accept: 'application/json' },
                    });
                    const payload = await response.json();
                    const items = payload.data || [];
                    if (items.length === 0) {
                        panel.classList.add('hidden');
                        results.innerHTML = '';
                        return;
                    }

                    results.innerHTML = items.map((item) => (
                        `<a class="block text-blue-800 hover:underline" href="${item.url}">${item.title}<span class="ml-2 text-xs text-blue-600">${item.category || ''}</span></a>`
                    )).join('');
                    panel.classList.remove('hidden');
                }, 250);
            });
        })();
    </script>
@endsection
