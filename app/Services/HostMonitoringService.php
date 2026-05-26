<?php

namespace App\Services;

use App\Models\DueReminder;
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

    public function sendDueReminders(int|array|string|null $days = null): array
    {
        $result = ['processed' => 0, 'notified' => 0, 'failed' => 0];
        $legacyWindowDays = is_int($days) ? $days : null;
        $reminderDays = $legacyWindowDays === null
            ? $this->normalizeReminderDays($days ?? config('billing.reminder_days', [7, 3, 1]))
            : [$legacyWindowDays];

        foreach ($reminderDays as $daysBefore) {
            Host::query()
                ->with(['client', 'product'])
                ->whereIn('status', ['Active', 'Suspended'])
                ->whereNotNull('next_due_date')
                ->when(
                    $legacyWindowDays === null,
                    fn ($query) => $query->whereDate('next_due_date', now()->addDays($daysBefore)->toDateString()),
                    fn ($query) => $query->whereBetween('next_due_date', [now(), now()->addDays($legacyWindowDays)])
                )
                ->orderBy('id')
                ->chunkById(100, function ($hosts) use (&$result, $daysBefore, $legacyWindowDays): void {
                    foreach ($hosts as $host) {
                        $actualDaysBefore = $legacyWindowDays === null
                            ? $daysBefore
                            : max(1, (int) now()->startOfDay()->diffInDays($host->next_due_date?->copy()->startOfDay(), false));
                        $result['processed']++;

                        if ($this->alreadySentDueReminder($host, $actualDaysBefore)) {
                            continue;
                        }

                        try {
                            $notification = app(NotificationService::class)->notifyClient($host->client, 'host_due_reminder', [
                                'client_name' => $host->client->username,
                                'product_name' => $host->product?->name ?? '服务',
                                'due_date' => $host->next_due_date?->format('Y-m-d'),
                                'days' => $actualDaysBefore,
                            ]);

                            $sent = ($notification['mail'] ?? false) === true || ($notification['sms'] ?? false) === true;
                            $this->logDueReminder($host, $sent, [
                                'due_date' => $host->next_due_date?->toDateTimeString(),
                                'days_before' => $actualDaysBefore,
                                'notification' => $notification,
                            ]);

                            if ($sent) {
                                DueReminder::query()->create([
                                    'host_id' => $host->id,
                                    'days_before' => $actualDaysBefore,
                                    'sent_at' => now(),
                                ]);
                                $result['notified']++;
                            } else {
                                $result['failed']++;
                            }
                        } catch (Throwable $exception) {
                            $result['failed']++;
                            $this->logDueReminder($host, false, [
                                'due_date' => $host->next_due_date?->toDateTimeString(),
                                'days_before' => $actualDaysBefore,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                });
        }

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

    private function normalizeReminderDays(int|array|string $days): array
    {
        $values = is_array($days) ? $days : explode(',', (string) $days);

        return collect($values)
            ->map(fn ($value): int => (int) trim((string) $value))
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->all() ?: [7, 3, 1];
    }

    private function alreadySentDueReminder(Host $host, int $daysBefore): bool
    {
        return DueReminder::query()
            ->where('host_id', $host->id)
            ->where('days_before', $daysBefore)
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
