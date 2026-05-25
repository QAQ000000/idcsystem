<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
            ->update([
                'status' => 'processing',
                'attempts' => \DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $log = SmsLog::query()->find($this->smsLogId);
        if (!$log) {
            return;
        }

        if (!$sms->sendLog($log)) {
            // sendLog 内部已将状态标记为 failed；抛异常触发队列重试
            throw new \RuntimeException('SMS notification failed for log #' . $log->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        // 耗尽重试次数后兜底：确保状态不卡在 processing
        SmsLog::query()
            ->whereKey($this->smsLogId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'success' => false,
                'error' => 'Job failed after all retries: ' . $exception->getMessage(),
            ]);
    }
}
