<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LogCleanupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LogController extends Controller
{
    public function index(LogCleanupService $logs)
    {
        return view('admin.logs.index', [
            'summaries' => $logs->summaries(),
        ]);
    }

    public function show(Request $request, string $type, LogCleanupService $logs)
    {
        abort_unless(in_array($type, $logs->supportedTables(), true), 404);
        abort_unless(Schema::hasTable($type), 404);

        $dateColumn = $logs->dateColumnFor($type);
        $search = $this->queryString($request, 'q');
        $status = $this->queryString($request, 'status');
        $from = $this->queryString($request, 'from');
        $to = $this->queryString($request, 'to');

        $query = DB::table($type);
        if ($search !== null) {
            $this->applySearch($query, $type, $search);
        }
        if ($status !== null && Schema::hasColumn($type, 'status')) {
            $query->where('status', $status);
        }
        if ($from !== null && $dateColumn && Schema::hasColumn($type, $dateColumn)) {
            $query->where($dateColumn, '>=', $from);
        }
        if ($to !== null && $dateColumn && Schema::hasColumn($type, $dateColumn)) {
            $query->where($dateColumn, '<=', $to);
        }

        $rows = $query
            ->orderByDesc($dateColumn && Schema::hasColumn($type, $dateColumn) ? $dateColumn : 'id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.logs.show', [
            'type' => $type,
            'rows' => $rows,
            'columns' => $this->displayColumns($type),
            'filters' => compact('search', 'status', 'from', 'to'),
        ]);
    }

    public function cleanup(LogCleanupService $logs)
    {
        $result = $logs->cleanup();

        return redirect()->route('admin.logs.index')->with('status', '日志清理已完成：' . json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    private function applySearch($query, string $type, string $search): void
    {
        $columns = match ($type) {
            'admin_action_logs' => ['action', 'result', 'ip_address'],
            'email_logs' => ['to', 'subject', 'template', 'status'],
            'sms_logs' => ['phone', 'template', 'status'],
            'client_login_logs' => ['ip', 'user_agent'],
            'login_attempts' => ['email', 'ip', 'status', 'failure_reason'],
            'webhook_deliveries' => ['event', 'status', 'response'],
            'client_activity_logs' => ['action', 'description', 'ip'],
            'usage_alert_logs' => ['metric'],
            'due_reminders' => ['days_before'],
            default => [],
        };

        $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($type, $column)));
        if ($columns === []) {
            return;
        }

        $query->where(function ($inner) use ($columns, $search): void {
            foreach ($columns as $column) {
                $inner->orWhere($column, 'like', '%' . $search . '%');
            }
        });
    }

    private function displayColumns(string $type): array
    {
        return match ($type) {
            'admin_action_logs' => ['id', 'admin_user_id', 'action', 'result', 'ip_address', 'created_at'],
            'email_logs' => ['id', 'to', 'subject', 'template', 'status', 'created_at'],
            'sms_logs' => ['id', 'phone', 'template', 'status', 'created_at'],
            'client_login_logs' => ['id', 'client_id', 'ip', 'logged_in_at'],
            'login_attempts' => ['id', 'email', 'ip', 'status', 'failure_reason', 'created_at'],
            'webhook_deliveries' => ['id', 'event', 'status', 'status_code', 'created_at'],
            'client_activity_logs' => ['id', 'client_id', 'action', 'description', 'created_at'],
            'usage_alert_logs' => ['id', 'host_id', 'metric', 'threshold', 'current_value', 'triggered_at'],
            'due_reminders' => ['id', 'host_id', 'days_before', 'sent_at'],
            default => ['id'],
        };
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
