<?php

namespace App\Http\Controllers\Api;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    /**
     * 获取当前客户账单列表。
     *
     * @response 200 {"success":true,"data":[{"id":1,"invoice_number":"INV-202605260001","status":"Unpaid"}],"meta":{"current_page":1}}
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->where('client_id', $request->user()->id)
            ->when($status = $this->queryString($request, 'status'), fn ($query) => $query->where('status', $status))
            ->when($from = $this->queryString($request, 'from'), fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to = $this->queryString($request, 'to'), fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate($this->perPage($request));

        return $this->list($invoices, fn (Invoice $invoice) => $this->invoicePayload($invoice));
    }

    /**
     * 获取账单详情。
     *
     * @response 200 {"success":true,"data":{"id":1,"invoice_number":"INV-202605260001","items":[]}}
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ((int) $invoice->client_id !== (int) $request->user()->id) {
            return $this->error('账单不存在。', 404);
        }

        $invoice->load('items');

        return $this->success($this->invoicePayload($invoice, true));
    }

    /**
     * 使用余额或信用额度支付账单。
     *
     * @response 200 {"success":true,"data":{"invoice":{"id":1,"status":"Paid"}}}
     */
    public function payWithCredit(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        if ((int) $invoice->client_id !== (int) $request->user()->id) {
            return $this->error('账单不存在。', 404);
        }

        if ($invoice->status !== 'Unpaid') {
            return $this->error('账单状态不可支付。', 422);
        }

        if (!$invoices->payWithCredit($invoice)) {
            return $this->error('余额或信用额度不足。', 422);
        }

        return $this->success([
            'invoice' => $this->invoicePayload($invoice->fresh(['items', 'client'])),
        ]);
    }

    private function invoicePayload(Invoice $invoice, bool $withItems = false): array
    {
        $payload = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) $invoice->tax,
            'credit_used' => (float) $invoice->credit_used,
            'total' => (float) $invoice->total,
            'payment_method' => $invoice->payment_method,
            'due_date' => $invoice->due_date?->toDateTimeString(),
            'paid_at' => $invoice->paid_at?->toDateTimeString(),
        ];

        if ($withItems) {
            $payload['items'] = $invoice->items
                ->map(fn (InvoiceItem $item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'description' => $item->description,
                    'amount' => (float) $item->amount,
                    'rel_id' => $item->rel_id,
                    'meta' => $item->meta,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 15), 1), 100);
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
}
