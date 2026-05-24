<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemTaskLog;
use Illuminate\Http\Request;

class SystemTaskController extends Controller
{
    public function index(Request $request)
    {
        $logs = SystemTaskLog::query()
            ->when($request->filled('task_name'), fn ($query) => $query->where('task_name', $request->string('task_name')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.system-tasks.index', [
            'logs' => $logs,
            'taskNames' => [
                'host:sync-usage',
                'host:send-due-reminders',
            ],
            'statuses' => ['running', 'success', 'failed'],
            'filters' => $request->only(['task_name', 'status']),
        ]);
    }
}
