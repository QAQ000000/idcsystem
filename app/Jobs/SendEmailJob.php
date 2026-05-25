<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Services\MailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

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
            throw new RuntimeException('Email notification failed for log #' . $log->id);
        }
    }
}
