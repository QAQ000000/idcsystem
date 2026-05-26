<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialStatement;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Product\Models\Product;
use App\Modules\User\Models\AffiliateCommission;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class FinancialStatementService
{
    public function generate(Carbon $periodStart, Carbon $periodEnd): FinancialStatement
    {
        $start = $periodStart->copy()->startOfDay();
        $end = $periodEnd->copy()->endOfDay();

        $incomeQuery = $this->paidInvoicesBetween($start, $end);
        $refundQuery = $this->refundsBetween($start, $end);
        $commissionQuery = $this->paidCommissionsBetween($start, $end);

        $totalIncome = round((float) $incomeQuery->sum('total'), 2);
        $totalRefund = round((float) $refundQuery->sum('amount'), 2);
        $totalCommission = round((float) $commissionQuery->sum('amount'), 2);

        return FinancialStatement::query()->create([
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'total_income' => $totalIncome,
            'total_refund' => $totalRefund,
            'total_commission' => $totalCommission,
            'net_income' => round($totalIncome - $totalRefund - $totalCommission, 2),
            'paid_invoice_count' => $incomeQuery->count(),
            'refund_count' => $refundQuery->count(),
            'breakdown' => [
                'income_by_payment_method' => $this->getIncomeBreakdown($start, $end),
                'income_by_product' => $this->getProductBreakdown($start, $end),
            ],
        ]);
    }

    public function getIncomeBreakdown(Carbon $periodStart, Carbon $periodEnd): array
    {
        return Account::query()
            ->selectRaw("COALESCE(NULLIF(payment_method, ''), 'unknown') as payment_method, COUNT(*) as count, SUM(amount) as total")
            ->where('type', 'credit')
            ->whereBetween('created_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => (string) $row->payment_method,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ])
            ->values()
            ->all();
    }

    public function getProductBreakdown(Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = InvoiceItem::query()
            ->selectRaw('rel_id as product_id, COUNT(*) as count, SUM(amount) as total')
            ->whereIn('type', ['product', 'renewal'])
            ->where('amount', '>', 0)
            ->whereHas('invoice', function ($query) use ($periodStart, $periodEnd): void {
                $query->where('status', 'Paid')
                    ->whereNotNull('paid_at')
                    ->whereBetween('paid_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()]);
            })
            ->groupBy('rel_id')
            ->orderByDesc('total')
            ->get();

        $products = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->map(fn ($id) => (int) $id)->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($products) {
            $product = $products->get((int) $row->product_id);

            return [
                'product_id' => (int) $row->product_id,
                'product_name' => $product?->name ?: '产品 #' . $row->product_id,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
            ];
        })->values()->all();
    }

    public function exportToExcel(FinancialStatement $statement): string
    {
        $statement->refresh();
        $breakdown = is_array($statement->breakdown) ? $statement->breakdown : [];
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('财务对账');

        $rows = [
            ['期间', $statement->period_start?->toDateString() . ' 至 ' . $statement->period_end?->toDateString()],
            ['总收入', (float) $statement->total_income],
            ['总退款', (float) $statement->total_refund],
            ['总佣金', (float) $statement->total_commission],
            ['净收入', (float) $statement->net_income],
            ['已付账单数', $statement->paid_invoice_count],
            ['退款笔数', $statement->refund_count],
            [],
            ['按支付方式统计'],
            ['支付方式', '笔数', '金额'],
        ];

        foreach ($breakdown['income_by_payment_method'] ?? [] as $item) {
            $rows[] = [$item['payment_method'] ?? 'unknown', (int) ($item['count'] ?? 0), (float) ($item['total'] ?? 0)];
        }

        $rows[] = [];
        $rows[] = ['按产品统计'];
        $rows[] = ['产品', '数量', '金额'];
        foreach ($breakdown['income_by_product'] ?? [] as $item) {
            $rows[] = [$item['product_name'] ?? '未知产品', (int) ($item['count'] ?? 0), (float) ($item['total'] ?? 0)];
        }

        $sheet->fromArray($rows);
        foreach (['A', 'B', 'C'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $directory = storage_path('app/financial-statements');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . sprintf(
            'financial-statement-%s-%s.xlsx',
            $statement->period_start?->format('Ymd'),
            $statement->period_end?->format('Ymd')
        );

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function paidInvoicesBetween(Carbon $start, Carbon $end)
    {
        return Invoice::query()
            ->where('status', 'Paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end]);
    }

    private function refundsBetween(Carbon $start, Carbon $end)
    {
        return Account::query()
            ->where('type', 'refund')
            ->whereBetween('created_at', [$start, $end]);
    }

    private function paidCommissionsBetween(Carbon $start, Carbon $end)
    {
        return AffiliateCommission::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end]);
    }
}
