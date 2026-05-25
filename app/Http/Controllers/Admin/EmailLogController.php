<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Services\AdminAuditService;
use App\Services\MailService;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->queryString($request, 'status');

        $logs = EmailLog::query()
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('admin.email-logs.index', compact('logs'));
    }

    public function show(EmailLog $emailLog)
    {
        return view('admin.email-logs.show', compact('emailLog'));
    }

    public function retry(Request $request, EmailLog $emailLog, MailService $mail, AdminAuditService $audit)
    {
        $success = $mail->retry($emailLog);
        $audit->record($request, 'email_log.retry', $emailLog, $success ? 'success' : 'failed', [
            'status' => $emailLog->fresh()->status,
        ], $success ? null : '邮件重新提交失败');

        if (!$success) {
            return redirect()->route('admin.email-logs.show', $emailLog)->with('error', '邮件重新提交失败');
        }

        return redirect()->route('admin.email-logs.show', $emailLog)->with('status', '邮件已重新提交处理');
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
