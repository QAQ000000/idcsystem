<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $importJobId)
    {
        $this->onQueue('default');
    }

    public function handle(ImportService $imports): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if (!$job || in_array($job->status, ['processing', 'completed'], true)) {
            return;
        }

        $imports->import($job);
    }
}
