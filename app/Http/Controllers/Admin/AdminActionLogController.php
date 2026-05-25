<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;

class AdminActionLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'action' => $this->queryString($request, 'action'),
            'result' => $this->queryString($request, 'result'),
        ];

        $logs = AdminActionLog::query()
            ->with('admin')
            ->when($filters['action'], fn ($query, string $action) => $query->where('action', $action))
            ->when($filters['result'], fn ($query, string $result) => $query->where('result', $result))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.admin-action-logs.index', [
            'logs' => $logs,
            'actions' => AdminActionLog::query()->distinct()->orderBy('action')->pluck('action'),
            'results' => ['success', 'failed'],
            'filters' => $filters,
        ]);
    }

    public function show(AdminActionLog $adminActionLog)
    {
        $adminActionLog->load('admin');

        return view('admin.admin-action-logs.show', ['log' => $adminActionLog]);
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
