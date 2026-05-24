<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

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
        $claimed = SmsLog::query()
            ->whereKey($this->smsLogId)
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'processing']);
        if ($claimed !== 1) {
            return;
        }

        $log = SmsLog::query()->find($this->smsLogId);
        if (!$log) {
            return;
        }

        if (!$sms->sendLog($log)) {
            throw new RuntimeException('SMS notification failed for log #' . $log->id);
        }
    }
}
