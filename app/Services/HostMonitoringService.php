<?php

namespace App\Services;

use App\Models\HostActionLog;
use App\Models\HostUsageSnapshot;
use App\Modules\Order\Models\Host;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use Throwable;

class HostMonitoringService
{
    public function collectUsageForHost(Host $host): HostUsageSnapshot
    {
        try {
            $plugin = $this->serverPlugin($host);
            if (!$plugin) {
                return $this->snapshot($host, [], 'Server module unavailable');
            }

            $stats = $plugin->getUsageStats($this->serverParams($host));

            return $this->snapshot($host, $stats);
        } catch (Throwable $exception) {
            HostActionLog::query()->create([
                'host_id' => $host->id,
                'action' => 'usage_sync_failed',
                'message' => $exception->getMessage(),
                'meta' => [],
            ]);

            return $this->snapshot($host, [], $exception->getMessage());
        }
    }

    public function syncUsage(): array
    {
        $result = ['processed' => 0, 'success' => 0, 'failed' => 0];

        Host::query()
            ->with('product')
            ->whereIn('status', ['Active', 'Suspended'])
            ->orderBy('id')
            ->chunkById(100, function ($hosts) use (&$result): void {
                foreach ($hosts as $host) {
                    $result['processed']++;
                    $snapshot = $this->collectUsageForHost($host);
                    $snapshot->error ? $result['failed']++ : $result['success']++;
                }
            });

        return $result;
    }

    public function sendDueReminders(int $days = 7): array
    {
        $result = ['processed' => 0, 'notified' => 0];

        Host::query()
            ->with(['client', 'product'])
            ->whereIn('status', ['Active', 'Suspended'])
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [now(), now()->addDays($days)])
            ->orderBy('id')
            ->chunkById(100, function ($hosts) use (&$result): void {
                foreach ($hosts as $host) {
                    $result['processed']++;

                    if ($this->recentlyReminded($host)) {
                        continue;
                    }

                    app(NotificationService::class)->notifyClient($host->client, 'host_due_reminder', [
                        'client_name' => $host->client->username,
                        'product_name' => $host->product?->name ?? '服务',
                        'due_date' => $host->next_due_date?->format('Y-m-d'),
                    ]);

                    HostActionLog::query()->create([
                        'host_id' => $host->id,
                        'action' => 'due_reminder',
                        'message' => '服务到期提醒已触发',
                        'meta' => ['due_date' => $host->next_due_date?->toDateTimeString()],
                    ]);

                    $result['notified']++;
                }
            });

        return $result;
    }

    private function snapshot(Host $host, array $stats, ?string $error = null): HostUsageSnapshot
    {
        return HostUsageSnapshot::query()->create([
            'host_id' => $host->id,
            'cpu' => isset($stats['cpu']) ? (float) $stats['cpu'] : null,
            'memory' => isset($stats['memory']) ? (float) $stats['memory'] : null,
            'disk' => isset($stats['disk']) ? (float) $stats['disk'] : null,
            'bandwidth' => isset($stats['bandwidth']) ? (float) $stats['bandwidth'] : null,
            'collected_at' => now(),
            'error' => $error,
        ]);
    }

    private function recentlyReminded(Host $host): bool
    {
        return HostActionLog::query()
            ->where('host_id', $host->id)
            ->where('action', 'due_reminder')
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    private function serverPlugin(Host $host): ?ServerModuleInterface
    {
        $serverType = $host->product?->server_type;
        if (!$serverType) {
            return null;
        }

        $plugin = Plugin::get($serverType);

        return $plugin instanceof ServerModuleInterface ? $plugin : null;
    }

    private function serverParams(Host $host): array
    {
        return [
            'host_id' => $host->id,
            'client_id' => $host->client_id,
            'product_id' => $host->product_id,
            'domain' => $host->domain,
            'username' => $host->username,
            'password' => $host->password,
            'billing_cycle' => $host->billing_cycle,
            'server_id' => $host->server_id,
            'config' => is_array($host->notes) ? $host->notes : [],
        ];
    }
}
