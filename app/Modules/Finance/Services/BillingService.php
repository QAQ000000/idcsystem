<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\HostService;
use App\Services\HostMonitoringService;
use Illuminate\Support\Facades\DB;

class BillingService
{
    private HostService $hostService;

    public function __construct(?HostService $hostService = null)
    {
        $this->hostService = $hostService ?? new HostService();
    }

    /**
     * 为需要续费的服务生成账单。
     */
    public function generateRecurringInvoices(): int
    {
        $count = 0;
        $daysBefore = (int) config('billing.invoice_days_before_due', 7);
        $cutoff = now()->addDays($daysBefore);

        Host::query()
            ->with(['client', 'product'])
            ->where('status', 'Active')
            ->where('auto_renew', true)
            ->whereNotNull('next_invoice_date')
            ->where('next_invoice_date', '<=', $cutoff)
            ->chunkById(100, function ($hosts) use (&$count) {
                foreach ($hosts as $host) {
                    DB::transaction(function () use ($host, &$count) {
                        if (!$host->client || $host->client->trashed() || !$host->client->isActive()) {
                            $host->actionLogs()->create([
                                'action' => 'renew_invoice_failed',
                                'message' => '客户账号状态不允许自动续费',
                                'meta' => [
                                    'client_id' => $host->client_id,
                                    'client_status' => $host->client?->status,
                                    'client_deleted' => (bool) $host->client?->trashed(),
                                ],
                            ]);

                            return;
                        }

                        if ($this->hasUnpaidRenewalInvoice($host)) {
                            return;
                        }

                        $this->hostService->renew($host, $host->billing_cycle);
                        $host->update(['next_invoice_date' => $host->next_due_date?->copy()->subDays(7)]);
                        $count++;
                    });
                }
            });

        return $count;
    }

    /**
     * 发送到期提醒，兼容旧调用点并委托给主机监控服务执行实际通知。
     */
    public function sendDueReminders(): int
    {
        $result = app(HostMonitoringService::class)->sendDueReminders();

        return (int) ($result['notified'] ?? 0);
    }

    /**
     * 暂停逾期未续费服务。
     */
    public function suspendOverdueHosts(): int
    {
        $count = 0;
        $graceDays = (int) config('billing.grace_days', 0);
        $cutoff = now()->subDays($graceDays);

        Host::query()
            ->where('status', 'Active')
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', $cutoff)
            ->chunkById(100, function ($hosts) use (&$count) {
                foreach ($hosts as $host) {
                    if ($this->hostService->suspend($host, 'Overdue payment')) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function hasUnpaidRenewalInvoice(Host $host): bool
    {
        return InvoiceItem::query()
            ->where('type', 'renewal')
            ->where('rel_id', $host->id)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'Unpaid'))
            ->exists();
    }
}
