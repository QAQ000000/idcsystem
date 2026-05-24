<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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

Artisan::command('host:send-due-reminders {--days=7}', function () {
    $days = max(1, (int) $this->option('days'));
    $task = app(\App\Services\SystemTaskService::class)->run('host:send-due-reminders', function () use ($days) {
        return app(\App\Services\HostMonitoringService::class)->sendDueReminders($days);
    });

    if ($task->status === 'failed') {
        $this->error($task->error ?: 'Host due reminders failed');
        return 1;
    }

    $this->info($task->output ?: 'Host due reminders completed');
    return 0;
})->purpose('Send host due reminder notifications');
