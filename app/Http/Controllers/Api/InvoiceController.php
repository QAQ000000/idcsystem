<?php

namespace App\Http\Controllers\Api;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->where('client_id', $request->user()->id)
            ->latest()
            ->paginate($this->perPage($request));

        return $this->list($invoices, fn (Invoice $invoice) => $this->invoicePayload($invoice));
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ((int) $invoice->client_id !== (int) $request->user()->id) {
            return $this->error('账单不存在。', 404);
        }

        $invoice->load('items');

        return $this->success($this->invoicePayload($invoice, true));
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
}
