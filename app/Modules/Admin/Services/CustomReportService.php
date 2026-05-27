<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\CustomReport;
use App\Modules\Admin\Models\CustomReportExecution;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CustomReportService
{
    public function execute(CustomReport $report): array
    {
        $started = microtime(true);

        try {
            $this->assertSafeSql((string) $report->query);
            $rows = collect(DB::select($this->limitedSql((string) $report->query)))
                ->map(fn (object $row) => (array) $row)
                ->values()
                ->all();

            $execution = $this->recordExecution($report, 'success', count($rows), $started);

            return [
                'rows' => $rows,
                'columns' => $this->columns($report, $rows),
                'execution' => $execution,
            ];
        } catch (Throwable $exception) {
            $this->recordExecution($report, 'failed', 0, $started, $exception->getMessage());

            throw $exception;
        }
    }

    public function exportCsv(CustomReport $report): StreamedResponse
    {
        $result = $this->execute($report);
        $filename = 'custom-report-' . $report->id . '-' . now()->format('YmdHis') . '.csv';

        return response()->streamDownload(function () use ($result): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $result['columns']);

            foreach ($result['rows'] as $row) {
                fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', $result['columns']));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function runScheduled(): int
    {
        $count = 0;

        CustomReport::query()
            ->whereNotNull('schedule')
            ->chunkById(50, function ($reports) use (&$count): void {
                foreach ($reports as $report) {
                    if (!$this->isDue($report)) {
                        continue;
                    }

                    try {
                        $this->execute($report);
                        $count++;
                    } catch (Throwable) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function assertSafeSql(string $sql): void
    {
        $trimmed = trim($sql);
        if ($trimmed === '') {
            throw new InvalidArgumentException('报表 SQL 不能为空。');
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $trimmed) ?: $trimmed);
        if (!preg_match('/^(select|with)\s/i', $trimmed)) {
            throw new InvalidArgumentException('自定义报表只允许 SELECT/WITH 只读查询。');
        }

        if (str_contains(rtrim($trimmed), ';')) {
            throw new InvalidArgumentException('自定义报表只允许单条 SQL。');
        }

        $blocked = 'insert|update|delete|drop|alter|truncate|create|replace|grant|revoke|attach|detach|pragma';
        if (preg_match('/\b(' . $blocked . ')\b/i', $normalized)) {
            throw new InvalidArgumentException('自定义报表 SQL 包含不允许的关键字。');
        }
    }

    private function limitedSql(string $sql): string
    {
        if (preg_match('/\blimit\s+\d+\b/i', $sql)) {
            return $sql;
        }

        return rtrim($sql) . ' limit 1000';
    }

    private function columns(CustomReport $report, array $rows): array
    {
        $configured = $report->columns ?? [];
        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        return $rows === [] ? [] : array_keys($rows[0]);
    }

    private function recordExecution(CustomReport $report, string $status, int $rows, float $started, ?string $error = null): CustomReportExecution
    {
        return CustomReportExecution::query()->create([
            'custom_report_id' => $report->id,
            'rows_count' => $rows,
            'execution_time' => max(1, (int) round((microtime(true) - $started) * 1000)),
            'status' => $status,
            'error' => $error,
            'executed_at' => now(),
        ]);
    }

    private function isDue(CustomReport $report): bool
    {
        $last = $report->executions()->latest('executed_at')->first()?->executed_at;

        return match ($report->schedule) {
            '* * * * *', 'every_minute' => true,
            'hourly', '0 * * * *' => !$last || $last->lte(now()->subHour()),
            'daily', '0 8 * * *' => !$last || $last->lte(now()->subDay()),
            default => false,
        };
    }
}
