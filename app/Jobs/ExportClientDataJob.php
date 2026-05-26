<?php

namespace App\Jobs;

use App\Models\DataExportRequest;
use App\Services\GdprService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportClientDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $requestId)
    {
        $this->onQueue('default');
    }

    public function handle(GdprService $gdpr): void
    {
        $request = DataExportRequest::query()->find($this->requestId);
        if (!$request || $request->status === 'completed') {
            return;
        }

        try {
            $gdpr->exportData($request);
        } catch (Throwable $exception) {
            $request->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
