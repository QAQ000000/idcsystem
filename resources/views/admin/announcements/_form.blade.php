@csrf
@if ($announcement->exists)
    @method('PUT')
@endif

<div class="grid gap-5 rounded bg-white p-6 shadow-sm md:grid-cols-2">
    <label class="block text-sm">
        标题
        <input class="mt-1 w-full rounded border px-3 py-2" name="title" value="{{ old('title', $announcement->title) }}" maxlength="200" required>
    </label>

    <label class="block text-sm">
        类型
        <select class="mt-1 w-full rounded border px-3 py-2" name="type" required>
            <option value="info" @selected(old('type', $announcement->type ?? 'info') === 'info')>信息</option>
            <option value="warning" @selected(old('type', $announcement->type) === 'warning')>警告</option>
            <option value="maintenance" @selected(old('type', $announcement->type) === 'maintenance')>维护</option>
        </select>
    </label>

    <label class="block text-sm">
        开始时间
        <input class="mt-1 w-full rounded border px-3 py-2" type="datetime-local" name="starts_at" value="{{ old('starts_at', $announcement->starts_at?->format('Y-m-d\TH:i')) }}">
    </label>

    <label class="block text-sm">
        结束时间
        <input class="mt-1 w-full rounded border px-3 py-2" type="datetime-local" name="ends_at" value="{{ old('ends_at', $announcement->ends_at?->format('Y-m-d\TH:i')) }}">
    </label>

    <label class="block text-sm md:col-span-2">
        内容
        <textarea class="mt-1 w-full rounded border px-3 py-2" name="content" rows="8" maxlength="5000" required>{{ old('content', $announcement->content) }}</textarea>
    </label>

    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="active" value="1" @checked(old('active', $announcement->active ?? true))>
        启用
    </label>
</div>

@if ($errors->any())
    <div class="mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
@endif

<div class="mt-5 flex gap-3">
    <button class="rounded bg-slate-900 px-4 py-2 text-white">保存公告</button>
    <a class="rounded border px-4 py-2" href="{{ route('admin.announcements.index') }}">返回列表</a>
</div>
