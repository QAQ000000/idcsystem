@extends('layouts.admin')

@section('title', '信用评分记录')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">信用评分记录</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $client->username }} / {{ $client->email }}</p>
        </div>
        <a class="rounded border px-4 py-2 text-sm" href="{{ route('admin.clients.show', $client) }}">返回客户详情</a>
    </div>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">原因</th>
                    <th class="px-4 py-3">原分数</th>
                    <th class="px-4 py-3">新分数</th>
                    <th class="px-4 py-3">详情</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $log->reason }}</td>
                        <td class="px-4 py-3">{{ $log->old_score }}</td>
                        <td class="px-4 py-3">{{ $log->new_score }}</td>
                        <td class="px-4 py-3">
                            <pre class="whitespace-pre-wrap rounded bg-slate-50 p-2 text-xs">{{ json_encode($log->details ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="5">暂无信用评分记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
