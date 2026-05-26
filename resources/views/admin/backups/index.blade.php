@extends('layouts.admin')

@section('title', '备份')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">备份</h1>
            <p class="mt-1 text-sm text-slate-500">管理数据库和上传文件备份。</p>
        </div>
        <div class="flex gap-2">
            <form method="post" action="{{ route('admin.backups.database') }}">
                @csrf
                <button class="rounded bg-slate-900 px-4 py-2 text-sm text-white">备份数据库</button>
            </form>
            <form method="post" action="{{ route('admin.backups.files') }}">
                @csrf
                <button class="rounded border px-4 py-2 text-sm">备份文件</button>
            </form>
        </div>
    </div>

    @if (session('error'))
        <div class="mb-6 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <form method="get" class="mb-6 flex gap-3 rounded bg-white p-5 shadow-sm">
        <select class="rounded border px-3 py-2" name="type">
            <option value="">全部类型</option>
            @foreach ($types as $item)
                <option value="{{ $item }}" @selected($type === $item)>{{ $item }}</option>
            @endforeach
        </select>
        <button class="rounded bg-slate-900 px-4 py-2 text-white">筛选</button>
        <a class="rounded border px-4 py-2" href="{{ route('admin.backups.index') }}">重置</a>
    </form>

    <section class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">文件</th>
                    <th class="px-4 py-3">大小</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($backups as $backup)
                    <tr>
                        <td class="px-4 py-3">{{ $backup->type }}</td>
                        <td class="px-4 py-3">{{ basename($backup->file_path) }}</td>
                        <td class="px-4 py-3">{{ number_format($backup->file_size / 1024, 2) }} KB</td>
                        <td class="px-4 py-3">{{ $backup->status }}</td>
                        <td class="px-4 py-3">{{ $backup->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex gap-3">
                                @if ($backup->status === 'completed')
                                    <a class="text-blue-600" href="{{ route('admin.backups.download', $backup) }}">下载</a>
                                @endif
                                @if ($backup->type === 'database' && $backup->status === 'completed')
                                    <form method="post" action="{{ route('admin.backups.restore', $backup) }}" onsubmit="return confirm('确定恢复该数据库备份？')">
                                        @csrf
                                        <button class="text-amber-700">恢复</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('admin.backups.destroy', $backup) }}">
                                    @csrf
                                    @method('delete')
                                    <button class="text-red-600">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-slate-500" colspan="6">暂无备份</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $backups->links() }}</div>
@endsection
