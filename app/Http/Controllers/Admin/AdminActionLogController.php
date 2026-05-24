<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;

class AdminActionLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AdminActionLog::query()
            ->with('admin')
            ->when($request->string('action')->toString(), fn ($query, string $action) => $query->where('action', $action))
            ->when($request->string('result')->toString(), fn ($query, string $result) => $query->where('result', $result))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.admin-action-logs.index', [
            'logs' => $logs,
            'actions' => AdminActionLog::query()->distinct()->orderBy('action')->pluck('action'),
            'results' => ['success', 'failed'],
            'filters' => $request->only(['action', 'result']),
        ]);
    }

    public function show(AdminActionLog $adminActionLog)
    {
        $adminActionLog->load('admin');

        return view('admin.admin-action-logs.show', ['log' => $adminActionLog]);
    }
}
