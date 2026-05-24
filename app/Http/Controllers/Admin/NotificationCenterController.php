<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\NotificationService;
use App\Services\SettingsService;

class NotificationCenterController extends Controller
{
    public function index(SettingsService $settings)
    {
        return view('admin.notifications.index', [
            'emailLogCount' => EmailLog::query()->count(),
            'smsLogCount' => SmsLog::query()->count(),
            'emailTemplateCount' => EmailTemplate::query()->count(),
            'smsTemplateCount' => SmsTemplate::query()->count(),
            'events' => NotificationService::events(),
            'settings' => $settings->all(),
        ]);
    }
}
