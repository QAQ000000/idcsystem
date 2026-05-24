<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Http\Request;

class SmsLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = SmsLog::query()
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('admin.sms-logs.index', compact('logs'));
    }

    public function show(SmsLog $smsLog)
    {
        return view('admin.sms-logs.show', compact('smsLog'));
    }

    public function retry(SmsLog $smsLog, SmsService $sms)
    {
        $sms->retry($smsLog);

        return redirect()->route('admin.sms-logs.show', $smsLog)->with('status', '短信已重新加入发送队列');
    }
}
