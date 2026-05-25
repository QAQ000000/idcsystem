<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Services\MailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $emailLogId)
    {
        $this->onQueue('notifications');
    }

    public function handle(MailService $mail): void
    {
        $claimed = EmailLog::query()
            ->whereKey($this->emailLogId)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'processing',
                'attempts' => \DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $log = EmailLog::query()->find($this->emailLogId);
        if (!$log) {
            return;
        }

        if (!$mail->sendLog($log)) {
            // sendLog 内部已将状态标记为 failed；抛异常触发队列重试
            throw new \RuntimeException('Email notification failed for log #' . $log->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        // 耗尽重试次数后兜底：确保状态不卡在 processing
        EmailLog::query()
            ->whereKey($this->emailLogId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'success' => false,
                'error' => 'Job failed after all retries: ' . $exception->getMessage(),
            ]);
    }
}
