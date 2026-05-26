<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('host:sync-usage', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('host:sync-usage', function () {
        return app(\App\Services\HostMonitoringService::class)->syncUsage();
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Host usage sync failed');
        return 1;
    }

    $this->info($task->output ?: 'Host usage sync completed');
    return 0;
})->purpose('Sync host usage snapshots from server modules');

Artisan::command('host:send-due-reminders {--days=}', function () {
    $days = $this->option('days');
    $task = app(\App\Services\SystemTaskService::class)->run('host:send-due-reminders', function () use ($days) {
        return app(\App\Services\HostMonitoringService::class)->sendDueReminders(
            $days === null || $days === '' ? null : max(1, (int) $days)
        );
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Host due reminders failed');
        return 1;
    }

    $this->info($task->output ?: 'Host due reminders completed');
    return 0;
})->purpose('Send host due reminder notifications');

Artisan::command('notifications:recover-stale {--minutes=15}', function () {
    $minutes = max(1, (int) $this->option('minutes'));
    $task = app(\App\Services\SystemTaskService::class)->run('notifications:recover-stale', function () use ($minutes) {
        return [
            'email' => app(\App\Services\MailService::class)->recoverStaleProcessing($minutes),
            'sms' => app(\App\Services\SmsService::class)->recoverStaleProcessing($minutes),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Notification recovery failed');
        return 1;
    }

    $this->info($task->output ?: 'Notification recovery completed');
    return 0;
})->purpose('Recover stale notification logs stuck in processing status');

Artisan::command('billing:generate-invoices', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('billing:generate-invoices', function () {
        return [
            'generated' => app(\App\Modules\Finance\Services\BillingService::class)->generateRecurringInvoices(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Recurring invoice generation failed');
        return 1;
    }

    $this->info($task->output ?: 'Recurring invoice generation completed');
    return 0;
})->purpose('Generate recurring invoices for auto-renew hosts');

Artisan::command('billing:suspend-overdue', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('billing:suspend-overdue', function () {
        return [
            'suspended' => app(\App\Modules\Finance\Services\BillingService::class)->suspendOverdueHosts(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Overdue host suspension failed');
        return 1;
    }

    $this->info($task->output ?: 'Overdue host suspension completed');
    return 0;
})->purpose('Suspend hosts with overdue unpaid invoices');

Artisan::command('cancel:process-approved', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('cancel:process-approved', function () {
        return [
            'completed' => app(\App\Modules\Order\Services\CancelRequestService::class)->processApproved(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Approved cancel request processing failed');
        return 1;
    }

    $this->info($task->output ?: 'Approved cancel request processing completed');
    return 0;
})->purpose('Process approved end-of-billing-period cancel requests');

// 核心业务调度：由系统 cron 每分钟触发 schedule:run 后按频率执行。
Schedule::command('billing:generate-invoices')->dailyAt('02:00');
Schedule::command('billing:suspend-overdue')->dailyAt('03:00');
Schedule::command('cancel:process-approved')->dailyAt('03:30');
Schedule::command('host:send-due-reminders')->dailyAt('09:00');
Schedule::command('host:sync-usage')->hourly();
Schedule::command('notifications:recover-stale')->everyFifteenMinutes();
