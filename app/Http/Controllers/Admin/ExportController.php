<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Credit;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\User\Models\Client;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function clients(Request $request): StreamedResponse
    {
        $keyword = $this->queryString($request, 'keyword');
        $rows = Client::query()
            ->when($keyword, function ($query, string $keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('username', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%");
                });
            })
            ->latest()
            ->cursor()
            ->map(fn (Client $client) => [
                $client->id,
                $client->username,
                $client->email,
                $client->phone,
                $client->company_name,
                $client->credit,
                $client->credit_limit,
                $client->status,
                $client->created_at?->toDateTimeString(),
            ]);

        return $this->streamCsv('clients.csv', ['ID', '用户名', '邮箱', '手机', '公司', '余额', '信用额度', '状态', '注册时间'], $rows);
    }

    public function invoices(Request $request): StreamedResponse
    {
        $status = $this->queryString($request, 'status');
        $dateFrom = $this->queryString($request, 'date_from');
        $dateTo = $this->queryString($request, 'date_to');

        $rows = Invoice::query()
            ->with('client')
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->when($dateFrom, fn ($query, string $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query, string $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->latest()
            ->cursor()
            ->map(fn (Invoice $invoice) => [
                $invoice->invoice_number,
                $invoice->client?->username,
                $invoice->status,
                $invoice->subtotal,
                $invoice->tax,
                $invoice->total,
                $invoice->payment_method,
                $invoice->due_date?->toDateString(),
                $invoice->paid_at?->toDateTimeString(),
                $invoice->created_at?->toDateTimeString(),
            ]);

        return $this->streamCsv('invoices.csv', ['账单号', '客户', '状态', '小计', '税额', '合计', '支付方式', '到期日', '支付时间', '创建时间'], $rows);
    }

    public function hosts(Request $request): StreamedResponse
    {
        $status = $this->queryString($request, 'status');
        $clientId = $this->queryInteger($request, 'client_id');
        $productId = $this->queryInteger($request, 'product_id');

        $rows = Host::query()
            ->with(['client', 'product'])
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->when($clientId, fn ($query, int $clientId) => $query->where('client_id', $clientId))
            ->when($productId, fn ($query, int $productId) => $query->where('product_id', $productId))
            ->latest('id')
            ->cursor()
            ->map(fn (Host $host) => [
                $host->id,
                $host->client?->username,
                $host->product?->name,
                $host->domain,
                $host->status,
                $host->billing_cycle,
                $host->next_due_date?->toDateString(),
                $host->created_at?->toDateTimeString(),
            ]);

        return $this->streamCsv('hosts.csv', ['ID', '客户', '产品', '域名', '状态', '计费周期', '到期日', '创建时间'], $rows);
    }

    public function credits(Request $request): StreamedResponse
    {
        $clientId = $this->queryInteger($request, 'client_id');
        $dateFrom = $this->queryString($request, 'date_from');
        $dateTo = $this->queryString($request, 'date_to');

        $rows = Credit::query()
            ->with('client')
            ->when($clientId, fn ($query, int $clientId) => $query->where('client_id', $clientId))
            ->when($dateFrom, fn ($query, string $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query, string $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->latest('id')
            ->cursor()
            ->map(fn (Credit $credit) => [
                $credit->id,
                $credit->client?->username,
                $credit->type,
                $credit->amount,
                $credit->balance,
                $credit->description,
                $credit->created_at?->toDateTimeString(),
            ]);

        return $this->streamCsv('credits.csv', ['ID', '客户', '类型', '金额', '余额快照', '描述', '时间'], $rows);
    }

    private function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function queryInteger(Request $request, string $key): ?int
    {
        $value = $this->queryString($request, $key);

        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
