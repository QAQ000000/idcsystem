<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class LogCleanupService
{
    private const TABLES = [
        'admin_action_logs' => 'created_at',
        'email_logs' => 'created_at',
        'sms_logs' => 'created_at',
        'client_login_logs' => 'logged_in_at',
        'webhook_deliveries' => 'created_at',
        'client_activity_logs' => 'created_at',
        'usage_alert_logs' => 'triggered_at',
        'login_attempts' => 'created_at',
        'due_reminders' => 'sent_at',
    ];

    public function cleanup(): array
    {
        $result = [];
        foreach (self::TABLES as $table => $dateColumn) {
            $retentionDays = (int) config("logging.retention.{$table}", 90);
            $result[$table] = $this->cleanupTable($table, $dateColumn, $retentionDays);
        }

        return $result;
    }

    public function cleanupTable(string $table, string $dateColumn, int $retentionDays): int
    {
        if (!array_key_exists($table, self::TABLES) || self::TABLES[$table] !== $dateColumn) {
            throw new InvalidArgumentException('不允许清理未注册的日志表。');
        }

        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $dateColumn)) {
            return 0;
        }

        $retentionDays = max(1, $retentionDays);

        return DB::table($table)
            ->whereNotNull($dateColumn)
            ->where($dateColumn, '<', now()->subDays($retentionDays))
            ->delete();
    }

    public function summaries(): array
    {
        $rows = [];
        foreach (self::TABLES as $table => $dateColumn) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);
            $rows[$table] = [
                'table' => $table,
                'date_column' => $dateColumn,
                'count' => (clone $query)->count(),
                'oldest' => Schema::hasColumn($table, $dateColumn) ? (clone $query)->min($dateColumn) : null,
                'latest' => Schema::hasColumn($table, $dateColumn) ? (clone $query)->max($dateColumn) : null,
                'retention_days' => (int) config("logging.retention.{$table}", 90),
            ];
        }

        return $rows;
    }

    public function dateColumnFor(string $table): ?string
    {
        return self::TABLES[$table] ?? null;
    }

    public function supportedTables(): array
    {
        return array_keys(self::TABLES);
    }
}
