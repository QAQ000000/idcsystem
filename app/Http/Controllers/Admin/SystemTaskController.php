<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemTaskLog;
use App\Services\AdminAuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;

class SystemTaskController extends Controller
{
    private const TASK_NAMES = [
        'billing:generate-invoices',
        'billing:suspend-overdue',
        'host:sync-usage',
        'host:send-due-reminders',
        'notifications:recover-stale',
    ];

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
            'taskNames' => self::TASK_NAMES,
            'statuses' => ['running', 'success', 'failed'],
            'filters' => $filters,
        ]);
    }

    public function runManual(Request $request, AdminAuditService $audit)
    {
        $data = $request->validate([
            'task_name' => ['required', 'string', 'in:' . implode(',', self::TASK_NAMES)],
        ]);

        $exitCode = Artisan::call($data['task_name']);
        $success = $exitCode === 0;
        $audit->record($request, 'system_task.run_manual', null, $success ? 'success' : 'failed', [
            'task_name' => $data['task_name'],
            'exit_code' => $exitCode,
        ], $success ? null : '系统任务执行失败');

        return redirect()->route('admin.system-tasks.index', ['task_name' => $data['task_name']])
            ->with($success ? 'status' : 'error', $success ? '系统任务已执行' : '系统任务执行失败');
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
