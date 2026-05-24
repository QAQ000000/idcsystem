<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $smsLogId)
    {
        $this->onQueue('notifications');
    }

    public function handle(SmsService $sms): void
    {
        $log = SmsLog::query()->find($this->smsLogId);

        if (!$log || $log->status === 'sent') {
            return;
        }

        $sms->sendLog($log);
    }
}
