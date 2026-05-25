<?php

namespace App\Services;

use App\Models\HostActionLog;
use App\Models\HostUsageSnapshot;
use App\Modules\Order\Models\Host;
use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Facades\Plugin;
use InvalidArgumentException;
use Throwable;

class HostMonitoringService
{
    public function normalizeUsageStats(array $stats): array
    {
        return [
            'cpu' => $this->normalizeUsageMetric($stats, 'cpu', 100),
            'memory' => $this->normalizeUsageMetric($stats, 'memory', 99999999.99),
            'disk' => $this->normalizeUsageMetric($stats, 'disk', 99999999.99),
            'bandwidth' => $this->normalizeUsageMetric($stats, 'bandwidth', 99999999.99),
        ];
    }

    public function collectUsageForHost(Host $host): HostUsageSnapshot
    {
        try {
            $plugin = $this->serverPlugin($host);
            if (!$plugin) {
                $this->logUsageFailure($host, 'Server module unavailable');

                return $this->snapshot($host, [], 'Server module unavailable');
            }

            $stats = $plugin->getUsageStats($this->usageStatsParams($host));

            return $this->snapshot($host, $stats);
        } catch (Throwable $exception) {
            $this->logUsageFailure($host, $exception->getMessage());

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
        $result = ['processed' => 0, 'notified' => 0, 'failed' => 0];

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

                    try {
                        $notification = app(NotificationService::class)->notifyClient($host->client, 'host_due_reminder', [
                            'client_name' => $host->client->username,
                            'product_name' => $host->product?->name ?? '服务',
                            'due_date' => $host->next_due_date?->format('Y-m-d'),
                        ]);

                        $sent = ($notification['mail'] ?? false) === true || ($notification['sms'] ?? false) === true;
                        $this->logDueReminder($host, $sent, [
                            'due_date' => $host->next_due_date?->toDateTimeString(),
                            'notification' => $notification,
                        ]);

                        if ($sent) {
                            $result['notified']++;
                        } else {
                            $result['failed']++;
                        }
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        $this->logDueReminder($host, false, [
                            'due_date' => $host->next_due_date?->toDateTimeString(),
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $result;
    }

    private function snapshot(Host $host, array $stats, ?string $error = null): HostUsageSnapshot
    {
        $normalizedStats = $error === null ? $this->normalizeUsageStats($stats) : [
            'cpu' => null,
            'memory' => null,
            'disk' => null,
            'bandwidth' => null,
        ];

        return HostUsageSnapshot::query()->create([
            'host_id' => $host->id,
            'cpu' => $normalizedStats['cpu'],
            'memory' => $normalizedStats['memory'],
            'disk' => $normalizedStats['disk'],
            'bandwidth' => $normalizedStats['bandwidth'],
            'collected_at' => now(),
            'error' => $error,
        ]);
    }

    private function normalizeUsageMetric(array $stats, string $key, float $max): ?float
    {
        if (!array_key_exists($key, $stats) || $stats[$key] === null || $stats[$key] === '') {
            return null;
        }

        if (!is_numeric($stats[$key])) {
            throw new InvalidArgumentException("Invalid usage metric: {$key}");
        }

        $value = (float) $stats[$key];

        // 服务器模块返回的数据会直接入库，必须先挡住非有限值和超出字段容量的数值。
        if (!is_finite($value) || $value < 0 || $value > $max) {
            throw new InvalidArgumentException("Invalid usage metric: {$key}");
        }

        return round($value, 2);
    }

    private function recentlyReminded(Host $host): bool
    {
        return HostActionLog::query()
            ->where('host_id', $host->id)
            ->whereIn('action', ['due_reminder', 'due_reminder_failed'])
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    private function logDueReminder(Host $host, bool $sent, array $meta): void
    {
        HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => $sent ? 'due_reminder' : 'due_reminder_failed',
            'message' => $sent ? '服务到期提醒已触发' : '服务到期提醒发送失败',
            'meta' => $meta,
        ]);
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

    private function usageStatsParams(Host $host): array
    {
        return [
            'host_id' => $host->id,
            'client_id' => $host->client_id,
            'product_id' => $host->product_id,
            'domain' => $host->domain,
            'username' => $host->username,
            'billing_cycle' => $host->billing_cycle,
            'server_id' => $host->server_id,
            'config' => is_array($host->notes) ? $host->notes : [],
        ];
    }

    private function logUsageFailure(Host $host, string $message): void
    {
        HostActionLog::query()->create([
            'host_id' => $host->id,
            'action' => 'usage_sync_failed',
            'message' => $message,
            'meta' => [],
        ]);
    }
}
