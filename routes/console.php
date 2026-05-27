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

Artisan::command('usage:check-alerts', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('usage:check-alerts', function () {
        return app(\App\Services\UsageAlertService::class)->checkAll();
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Usage alert check failed');
        return 1;
    }

    $this->info($task->output ?: 'Usage alert check completed');
    return 0;
})->purpose('Check host usage alerts');

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

Artisan::command('campaigns:send-scheduled', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('campaigns:send-scheduled', function () {
        return [
            'dispatched' => app(\App\Services\EmailCampaignService::class)->sendScheduled(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Scheduled email campaign dispatch failed');
        return 1;
    }

    $this->info($task->output ?: 'Scheduled email campaign dispatch completed');
    return 0;
})->purpose('Dispatch scheduled email campaigns');

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

Artisan::command('domains:send-expiry-reminders', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('domains:send-expiry-reminders', function () {
        return [
            'sent' => app(\App\Modules\Product\Services\DomainService::class)->sendExpiryReminders(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Domain expiry reminders failed');
        return 1;
    }

    $this->info($task->output ?: 'Domain expiry reminders completed');
    return 0;
})->purpose('Send domain expiry reminder notifications');

Artisan::command('domains:auto-renew', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('domains:auto-renew', function () {
        return [
            'generated' => app(\App\Modules\Product\Services\DomainService::class)->autoRenewDue(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Domain auto renew failed');
        return 1;
    }

    $this->info($task->output ?: 'Domain auto renew completed');
    return 0;
})->purpose('Generate renewal invoices for auto-renew domains');

Artisan::command('ssl:auto-renew-letsencrypt', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('ssl:auto-renew-letsencrypt', function () {
        return [
            'renewed' => app(\App\Modules\Product\Services\SslService::class)->autoRenewLetsEncryptDue(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Let’s Encrypt auto renew failed');
        return 1;
    }

    $this->info($task->output ?: 'Let’s Encrypt auto renew completed');
    return 0;
})->purpose('Auto renew Let’s Encrypt certificates');

Artisan::command('ssl:send-expiry-reminders', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('ssl:send-expiry-reminders', function () {
        return [
            'sent' => app(\App\Modules\Product\Services\SslService::class)->sendExpiryReminders(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'SSL expiry reminders failed');
        return 1;
    }

    $this->info($task->output ?: 'SSL expiry reminders completed');
    return 0;
})->purpose('Send SSL certificate expiry reminder notifications');

Artisan::command('backup:database', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('backup:database', function () {
        $backup = app(\App\Services\BackupService::class)->backupDatabase();

        return [
            'backup_id' => $backup->id,
            'status' => $backup->status,
            'file_size' => $backup->file_size,
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Database backup failed');
        return 1;
    }

    $this->info($task->output ?: 'Database backup completed');
    return 0;
})->purpose('Create database backup');

Artisan::command('backup:files', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('backup:files', function () {
        $backup = app(\App\Services\BackupService::class)->backupFiles();

        return [
            'backup_id' => $backup->id,
            'status' => $backup->status,
            'file_size' => $backup->file_size,
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'File backup failed');
        return 1;
    }

    $this->info($task->output ?: 'File backup completed');
    return 0;
})->purpose('Create uploaded files backup');

Artisan::command('backup:cleanup {--days=}', function () {
    $days = $this->option('days');
    $task = app(\App\Services\SystemTaskService::class)->run('backup:cleanup', function () use ($days) {
        return [
            'deleted' => app(\App\Services\BackupService::class)->cleanup((int) ($days ?: config('backup.keep_days', 30))),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Backup cleanup failed');
        return 1;
    }

    $this->info($task->output ?: 'Backup cleanup completed');
    return 0;
})->purpose('Delete expired backup files');

Artisan::command('logs:cleanup', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('logs:cleanup', function () {
        return app(\App\Services\LogCleanupService::class)->cleanup();
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Log cleanup failed');
        return 1;
    }

    $this->info($task->output ?: 'Log cleanup completed');
    return 0;
})->purpose('Delete expired operational logs');

Artisan::command('financial:generate-monthly-statement', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('financial:generate-monthly-statement', function () {
        $period = now()->subMonthNoOverflow();
        $statement = app(\App\Modules\Finance\Services\FinancialStatementService::class)->generate(
            $period->copy()->startOfMonth(),
            $period->copy()->endOfMonth()
        );

        return [
            'statement_id' => $statement->id,
            'period_start' => $statement->period_start?->toDateString(),
            'period_end' => $statement->period_end?->toDateString(),
            'net_income' => (float) $statement->net_income,
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Monthly financial statement generation failed');
        return 1;
    }

    $this->info($task->output ?: 'Monthly financial statement generated');
    return 0;
})->purpose('Generate previous month financial statement');

Artisan::command('tickets:check-sla-breaches', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('tickets:check-sla-breaches', function () {
        return [
            'breaches' => app(\App\Modules\Ticket\Services\SlaService::class)->checkBreaches(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Ticket SLA breach check failed');
        return 1;
    }

    $this->info($task->output ?: 'Ticket SLA breach check completed');
    return 0;
})->purpose('Check ticket SLA breaches');

Artisan::command('products:check-stock-alerts', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('products:check-stock-alerts', function () {
        return [
            'created' => app(\App\Modules\Product\Services\ProductService::class)->checkStockAlerts(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Product stock alert check failed');
        return 1;
    }

    $this->info($task->output ?: 'Product stock alert check completed');
    return 0;
})->purpose('Check product stock alerts');

Artisan::command('credit:recalculate-scores', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('credit:recalculate-scores', function () {
        return [
            'clients' => app(\App\Modules\User\Services\CreditScoreService::class)->recalculateAll(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Credit score recalculation failed');
        return 1;
    }

    $this->info($task->output ?: 'Credit score recalculation completed');
    return 0;
})->purpose('Recalculate client credit scores');

Artisan::command('custom-reports:run-scheduled', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('custom-reports:run-scheduled', function () {
        return [
            'executed' => app(\App\Modules\Admin\Services\CustomReportService::class)->runScheduled(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Scheduled custom reports failed');
        return 1;
    }

    $this->info($task->output ?: 'Scheduled custom reports completed');
    return 0;
})->purpose('Run due custom reports');

Artisan::command('client-segments:refresh', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('client-segments:refresh', function () {
        return [
            'segments' => app(\App\Modules\User\Services\ClientSegmentService::class)->refreshDynamicSegments(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Client segment refresh failed');
        return 1;
    }

    $this->info($task->output ?: 'Client segment refresh completed');
    return 0;
})->purpose('Refresh dynamic client segments');

Artisan::command('marketing-automations:process-due', function () {
    $task = app(\App\Services\SystemTaskService::class)->run('marketing-automations:process-due', function () {
        return [
            'executions' => app(\App\Modules\Support\Services\MarketingAutomationService::class)->processDueExecutions(),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Marketing automation processing failed');
        return 1;
    }

    $this->info($task->output ?: 'Marketing automation processing completed');
    return 0;
})->purpose('Process due marketing automation steps');

Artisan::command('api-quotas:check-alerts {--threshold=80}', function () {
    $threshold = max(1, min(100, (int) $this->option('threshold')));
    $task = app(\App\Services\SystemTaskService::class)->run('api-quotas:check-alerts', function () use ($threshold) {
        return [
            'sent' => app(\App\Modules\Admin\Services\ApiQuotaService::class)->checkQuotaAlerts($threshold),
        ];
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'API quota alert check failed');
        return 1;
    }

    $this->info($task->output ?: 'API quota alert check completed');
    return 0;
})->purpose('Check API quota usage and send alerts');

// 核心业务调度：由系统 cron 每分钟触发 schedule:run 后按频率执行。
Schedule::command('logs:cleanup')->dailyAt('01:00');
Schedule::command('billing:generate-invoices')->dailyAt('02:00');
Schedule::command('backup:database')->dailyAt('02:30');
Schedule::command('billing:suspend-overdue')->dailyAt('03:00');
Schedule::command('cancel:process-approved')->dailyAt('03:30');
Schedule::command('domains:auto-renew')->dailyAt('04:00');
Schedule::command('backup:cleanup')->dailyAt('04:15');
Schedule::command('ssl:auto-renew-letsencrypt')->dailyAt('04:30');
Schedule::command('backup:files')->weeklyOn(0, '03:00');
Schedule::command('financial:generate-monthly-statement')->monthlyOn(1, '05:00');
Schedule::command('credit:recalculate-scores')->monthlyOn(1, '05:30');
Schedule::command('host:send-due-reminders')->dailyAt('09:00');
Schedule::command('domains:send-expiry-reminders')->dailyAt('09:30');
Schedule::command('ssl:send-expiry-reminders')->dailyAt('10:00');
Schedule::command('host:sync-usage')->hourly();
Schedule::command('usage:check-alerts')->hourly();
Schedule::command('notifications:recover-stale')->everyFifteenMinutes();
Schedule::command('campaigns:send-scheduled')->everyMinute();
Schedule::command('tickets:check-sla-breaches')->everyFifteenMinutes();
Schedule::command('products:check-stock-alerts')->hourly();
Schedule::command('custom-reports:run-scheduled')->everyMinute();
Schedule::command('client-segments:refresh')->dailyAt('03:45');
Schedule::command('marketing-automations:process-due')->everyMinute();
Schedule::command('api-quotas:check-alerts')->hourly();
