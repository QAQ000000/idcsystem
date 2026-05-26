<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\CancelRequest;
use App\Modules\Order\Models\Host;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CancelRequestService
{
    public function __construct(
        private ?HostService $hosts = null,
        private ?NotificationService $notifications = null
    ) {
        $this->hosts ??= app(HostService::class);
        $this->notifications ??= app(NotificationService::class);
    }

    public function create(Host $host, string $type, ?string $reason): CancelRequest
    {
        return DB::transaction(function () use ($host, $type, $reason) {
            $lockedHost = Host::query()->with(['client', 'product'])->whereKey($host->id)->lockForUpdate()->firstOrFail();
            $this->assertCancellable($lockedHost);

            if ($lockedHost->cancelRequests()->where('status', 'pending')->exists()) {
                throw new RuntimeException('该服务已有待处理取消申请。');
            }

            $cancelRequest = CancelRequest::query()->create([
                'client_id' => $lockedHost->client_id,
                'host_id' => $lockedHost->id,
                'type' => $type,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $this->log($lockedHost, 'cancel_request_created', '客户已提交取消申请', [
                'cancel_request_id' => $cancelRequest->id,
                'type' => $type,
            ]);
            $this->notify($cancelRequest->fresh(['client', 'host.product']), 'host_cancel_requested');

            return $cancelRequest->fresh(['client', 'host.product']);
        });
    }

    public function approve(CancelRequest $cancelRequest, ?string $adminNotes = null): bool
    {
        return DB::transaction(function () use ($cancelRequest, $adminNotes) {
            $lockedRequest = $this->lockRequest($cancelRequest);
            if (!$lockedRequest || $lockedRequest->status !== 'pending') {
                return false;
            }

            $lockedRequest->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes,
                'approved_at' => now(),
            ]);

            $this->log($lockedRequest->host, 'cancel_request_approved', '取消申请已批准', [
                'cancel_request_id' => $lockedRequest->id,
                'type' => $lockedRequest->type,
            ]);
            $this->notify($lockedRequest->fresh(['client', 'host.product']), 'host_cancel_approved');

            if ($lockedRequest->type === 'immediate') {
                return $this->execute($lockedRequest->fresh());
            }

            return true;
        });
    }

    public function reject(CancelRequest $cancelRequest, string $reason): bool
    {
        return DB::transaction(function () use ($cancelRequest, $reason) {
            $lockedRequest = $this->lockRequest($cancelRequest);
            if (!$lockedRequest || $lockedRequest->status !== 'pending') {
                return false;
            }

            $lockedRequest->update([
                'status' => 'rejected',
                'admin_notes' => $reason,
            ]);

            $this->log($lockedRequest->host, 'cancel_request_rejected', '取消申请已拒绝', [
                'cancel_request_id' => $lockedRequest->id,
            ]);
            $this->notify($lockedRequest->fresh(['client', 'host.product']), 'host_cancel_rejected');

            return true;
        });
    }

    public function execute(CancelRequest $cancelRequest): bool
    {
        return DB::transaction(function () use ($cancelRequest) {
            $lockedRequest = $this->lockRequest($cancelRequest);
            if (!$lockedRequest || $lockedRequest->status !== 'approved') {
                return false;
            }

            if (!$this->hosts->terminate($lockedRequest->host)) {
                $this->log($lockedRequest->host, 'cancel_request_execute_failed', '取消申请执行失败', [
                    'cancel_request_id' => $lockedRequest->id,
                ]);

                return false;
            }

            $lockedRequest->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->log($lockedRequest->host->fresh(), 'cancel_request_completed', '取消申请已执行', [
                'cancel_request_id' => $lockedRequest->id,
            ]);
            $this->notify($lockedRequest->fresh(['client', 'host.product']), 'host_cancel_completed');

            return true;
        });
    }

    public function processApproved(): int
    {
        $count = 0;

        CancelRequest::query()
            ->with('host')
            ->where('status', 'approved')
            ->where('type', 'end_of_billing_period')
            ->whereHas('host', fn ($query) => $query->whereNotNull('next_due_date')->where('next_due_date', '<=', now()))
            ->chunkById(100, function ($requests) use (&$count): void {
                foreach ($requests as $request) {
                    if ($this->execute($request)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function assertCancellable(Host $host): void
    {
        if (!in_array($host->status, ['Active', 'Suspended'], true)) {
            throw new RuntimeException('当前服务状态不允许申请取消。');
        }

        if (!$host->client || $host->client->trashed() || !$host->client->isActive()) {
            throw new RuntimeException('客户账号状态不允许申请取消。');
        }
    }

    private function lockRequest(CancelRequest $cancelRequest): ?CancelRequest
    {
        return CancelRequest::query()
            ->with(['client', 'host.product'])
            ->whereKey($cancelRequest->id)
            ->lockForUpdate()
            ->first();
    }

    private function log(Host $host, string $action, string $message, array $meta = []): void
    {
        $host->actionLogs()->create([
            'action' => $action,
            'message' => $message,
            'meta' => $meta,
        ]);
    }

    private function notify(CancelRequest $cancelRequest, string $event): void
    {
        if (!$cancelRequest->client) {
            return;
        }

        $this->notifications->notifyClient($cancelRequest->client, $event, [
            'client_name' => $cancelRequest->client->username,
            'product_name' => $cancelRequest->host?->product?->name,
            'host_id' => $cancelRequest->host_id,
            'cancel_type' => $cancelRequest->type,
            'cancel_reason' => $cancelRequest->reason,
            'admin_notes' => $cancelRequest->admin_notes,
        ]);
    }
}
