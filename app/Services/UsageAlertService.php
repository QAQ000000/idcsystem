<?php

namespace App\Services;

use App\Models\HostUsageSnapshot;
use App\Models\UsageAlert;
use App\Models\UsageAlertLog;
use App\Modules\Order\Models\Host;
use Throwable;

class UsageAlertService
{
    public function check(Host $host): int
    {
        $snapshot = $this->latestSnapshot($host);
        if (!$snapshot) {
            return 0;
        }

        $triggered = 0;
        $host->loadMissing(['client', 'product']);
        foreach ($host->usageAlerts()->where('active', true)->get() as $alert) {
            $currentValue = $snapshot->{$alert->metric};
            if ($currentValue === null) {
                continue;
            }

            if ($this->shouldTrigger($alert, (float) $currentValue)) {
                $this->trigger($alert->loadMissing('host.client', 'host.product'), (float) $currentValue);
                $triggered++;
            }
        }

        return $triggered;
    }

    public function checkAll(): array
    {
        $result = ['processed' => 0, 'triggered' => 0, 'failed' => 0];

        Host::query()
            ->with(['client', 'product'])
            ->whereIn('status', ['Active', 'Suspended'])
            ->whereHas('usageAlerts', fn ($query) => $query->where('active', true))
            ->orderBy('id')
            ->chunkById(100, function ($hosts) use (&$result): void {
                foreach ($hosts as $host) {
                    $result['processed']++;
                    try {
                        $result['triggered'] += $this->check($host);
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        $host->actionLogs()->create([
                            'action' => 'usage_alert_failed',
                            'message' => '服务用量告警检查失败',
                            'meta' => ['error' => $exception->getMessage()],
                        ]);
                    }
                }
            });

        return $result;
    }

    public function trigger(UsageAlert $alert, float $currentValue): void
    {
        $host = $alert->host;
        if (!$host || !$host->client || $host->client->trashed() || !$host->client->isActive()) {
            return;
        }

        $triggeredAt = now();
        UsageAlertLog::query()->create([
            'alert_id' => $alert->id,
            'host_id' => $host->id,
            'metric' => $alert->metric,
            'threshold' => $alert->threshold,
            'current_value' => round($currentValue, 2),
            'triggered_at' => $triggeredAt,
        ]);

        $alert->update(['last_triggered_at' => $triggeredAt]);

        $variables = [
            'client_name' => $host->client->username,
            'product_name' => $host->product?->name ?? '服务',
            'host_id' => $host->id,
            'metric' => $this->metricLabel($alert->metric),
            'current_value' => number_format($currentValue, 2, '.', ''),
            'threshold' => $alert->threshold,
        ];
        $notification = app(NotificationService::class)->notifyClient($host->client, 'usage_alert', $variables);

        $host->actionLogs()->create([
            'action' => 'usage_alert',
            'message' => '服务用量告警已触发',
            'meta' => [
                'alert_id' => $alert->id,
                'metric' => $alert->metric,
                'threshold' => $alert->threshold,
                'current_value' => round($currentValue, 2),
                'notification' => $notification,
            ],
        ]);
    }

    public function shouldTrigger(UsageAlert $alert, float $currentValue): bool
    {
        if (!$alert->active || $currentValue < (float) $alert->threshold) {
            return false;
        }

        return !$alert->last_triggered_at || $alert->last_triggered_at->lte(now()->subHour());
    }

    private function latestSnapshot(Host $host): ?HostUsageSnapshot
    {
        return $host->usageSnapshots()
            ->whereNull('error')
            ->latest('collected_at')
            ->latest('id')
            ->first();
    }

    private function metricLabel(string $metric): string
    {
        return [
            'cpu' => 'CPU',
            'memory' => '内存',
            'disk' => '磁盘',
            'bandwidth' => '带宽',
        ][$metric] ?? $metric;
    }
}
