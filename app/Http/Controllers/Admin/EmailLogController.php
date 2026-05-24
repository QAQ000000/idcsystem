<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Services\MailService;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = EmailLog::query()
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('admin.email-logs.index', compact('logs'));
    }

    public function show(EmailLog $emailLog)
    {
        return view('admin.email-logs.show', compact('emailLog'));
    }

    public function retry(EmailLog $emailLog, MailService $mail)
    {
        $mail->retry($emailLog);

        return redirect()->route('admin.email-logs.show', $emailLog)->with('status', '邮件已重新加入发送队列');
    }
}
