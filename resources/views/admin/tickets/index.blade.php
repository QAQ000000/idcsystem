@extends('layouts.admin')

@section('title', '工单列表')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">工单列表</h1>

    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-medium">ID</th>
                    <th class="px-4 py-3 font-medium">工单号</th>
                    <th class="px-4 py-3 font-medium">主题</th>
                    <th class="px-4 py-3 font-medium">客户</th>
                    <th class="px-4 py-3 font-medium">状态</th>
                    <th class="px-4 py-3 font-medium">SLA</th>
                    <th class="px-4 py-3 font-medium">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tickets as $ticket)
                    <tr>
                        <td class="px-4 py-3">{{ $ticket->id }}</td>
                        <td class="px-4 py-3">{{ $ticket->ticket_number }}</td>
                        <td class="px-4 py-3">{{ $ticket->subject }}</td>
                        <td class="px-4 py-3">
                            {{ $ticket->client?->username ?: '-' }}
                            @if ($ticket->client?->trashed())
                                <span class="ml-1 inline-flex rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-700">已删除</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $ticket->status?->name }}</td>
                        <td class="px-4 py-3">
                            @if ($ticket->slaLog)
                                <span @class([
                                    'inline-flex rounded px-2 py-0.5 text-xs',
                                    'bg-red-100 text-red-700' => $ticket->slaLog->response_breached || $ticket->slaLog->resolution_breached,
                                    'bg-green-100 text-green-700' => !($ticket->slaLog->response_breached || $ticket->slaLog->resolution_breached),
                                ])>
                                    {{ $ticket->slaLog->response_breached || $ticket->slaLog->resolution_breached ? '已超时' : '正常' }}
                                </span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-3"><a class="text-blue-600" href="{{ route('admin.tickets.show', $ticket) }}">查看</a></td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6 text-center text-slate-500" colspan="7">暂无数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tickets->links() }}</div>
@endsection
