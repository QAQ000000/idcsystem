<?php

namespace App\Modules\Finance\Services;

use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\HostService;
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

        Host::query()
            ->with(['client', 'product'])
            ->where('status', 'Active')
            ->where('auto_renew', true)
            ->whereNotNull('next_invoice_date')
            ->where('next_invoice_date', '<=', now())
            ->chunkById(100, function ($hosts) use (&$count) {
                foreach ($hosts as $host) {
                    DB::transaction(function () use ($host, &$count) {
                        $this->hostService->renew($host, $host->billing_cycle);
                        $host->update(['next_invoice_date' => $host->next_due_date?->copy()->subDays(7)]);
                        $count++;
                    });
                }
            });

        return $count;
    }

    /**
     * 发送到期提醒，当前阶段记录待提醒数量，后续接入邮件/短信队列。
     */
    public function sendDueReminders(): int
    {
        return Host::query()
            ->where('status', 'Active')
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [now(), now()->addDays(7)])
            ->count();
    }

    /**
     * 暂停逾期未续费服务。
     */
    public function suspendOverdueHosts(): int
    {
        $count = 0;

        Host::query()
            ->where('status', 'Active')
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', now())
            ->chunkById(100, function ($hosts) use (&$count) {
                foreach ($hosts as $host) {
                    if ($this->hostService->suspend($host, 'Overdue payment')) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
