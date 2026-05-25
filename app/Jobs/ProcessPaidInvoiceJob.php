<?php

namespace App\Jobs;

use App\Models\SystemTaskLog;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Services\HostService;
use App\Modules\User\Services\AffiliateService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessPaidInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $invoiceId)
    {
        $this->onQueue('default');
    }

    public function handle(HostService $hostService, NotificationService $notifications, AffiliateService $affiliates): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        if (!$invoice || $invoice->status !== 'Paid') {
            return;
        }

        $fresh = $invoice->fresh(['order.hosts.product', 'items', 'client']);
        if (!$fresh) {
            return;
        }

        $hostService->applyPaidInvoice($fresh);

        if ($fresh->client) {
            $notifications->notifyClient($fresh->client, 'invoice_paid', [
                'client_name' => $fresh->client->username,
                'invoice_number' => $fresh->invoice_number,
                'amount' => $fresh->total,
            ]);
        }

        $affiliates->recordPayment($fresh);
    }

    public function failed(Throwable $exception): void
    {
        SystemTaskLog::query()->create([
            'task_name' => 'invoice:process-paid',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 0,
            'output' => json_encode(['invoice_id' => $this->invoiceId], JSON_UNESCAPED_UNICODE),
            'error' => $exception->getMessage(),
        ]);
    }
}
