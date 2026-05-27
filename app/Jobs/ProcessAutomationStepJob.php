<?php

namespace App\Jobs;

use App\Modules\Support\Models\MarketingAutomationExecution;
use App\Modules\Support\Services\MarketingAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAutomationStepJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $executionId)
    {
        $this->onQueue('notifications');
    }

    public function handle(MarketingAutomationService $automations): void
    {
        $execution = MarketingAutomationExecution::query()->find($this->executionId);
        if (!$execution || $execution->status !== 'running') {
            return;
        }

        $automations->executeNextStep($execution);
    }
}
