<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Services\AdminAuditService;
use App\Services\SmsService;
use Illuminate\Http\Request;

class SmsLogController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->queryString($request, 'status');

        $logs = SmsLog::query()
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('admin.sms-logs.index', compact('logs'));
    }

    public function show(SmsLog $smsLog)
    {
        return view('admin.sms-logs.show', compact('smsLog'));
    }

    public function retry(Request $request, SmsLog $smsLog, SmsService $sms, AdminAuditService $audit)
    {
        $success = $sms->retry($smsLog);
        $audit->record($request, 'sms_log.retry', $smsLog, $success ? 'success' : 'failed', [
            'status' => $smsLog->fresh()->status,
        ], $success ? null : '短信重新提交失败');

        if (!$success) {
            return redirect()->route('admin.sms-logs.show', $smsLog)->with('error', '短信重新提交失败');
        }

        return redirect()->route('admin.sms-logs.show', $smsLog)->with('status', '短信已重新提交处理');
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
