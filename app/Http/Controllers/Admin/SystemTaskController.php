<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemTaskLog;
use Illuminate\Http\Request;

class SystemTaskController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'task_name' => $this->queryString($request, 'task_name'),
            'status' => $this->queryString($request, 'status'),
        ];

        $logs = SystemTaskLog::query()
            ->when($filters['task_name'], fn ($query, string $taskName) => $query->where('task_name', $taskName))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.system-tasks.index', [
            'logs' => $logs,
            'taskNames' => [
                'billing:generate-invoices',
                'billing:suspend-overdue',
                'host:sync-usage',
                'host:send-due-reminders',
                'notifications:recover-stale',
            ],
            'statuses' => ['running', 'success', 'failed'],
            'filters' => $filters,
        ]);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
