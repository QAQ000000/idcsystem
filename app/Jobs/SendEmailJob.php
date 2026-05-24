<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Services\MailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $log = EmailLog::query()->find($this->emailLogId);

        if (!$log || $log->status === 'sent') {
            return;
        }

        $mail->sendLog($log);
    }
}
