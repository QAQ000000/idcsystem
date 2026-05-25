<?php

namespace App\Http\Controllers\Api;

use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\HostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class HostController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $hosts = Host::query()
            ->with('product')
            ->where('client_id', $request->user()->id)
            ->latest()
            ->paginate($this->perPage($request));

        return $this->list($hosts, fn (Host $host) => $this->hostPayload($host));
    }

    public function show(Request $request, Host $host): JsonResponse
    {
        if ((int) $host->client_id !== (int) $request->user()->id) {
            return $this->error('服务不存在。', 404);
        }

        $host->load(['product', 'order.invoice']);

        return $this->success($this->hostPayload($host));
    }

    public function renew(Request $request, Host $host, HostService $hosts): JsonResponse
    {
        if ((int) $host->client_id !== (int) $request->user()->id) {
            return $this->error('服务不存在。', 404);
        }

        $data = $request->validate([
            'billing_cycle' => ['nullable', 'string', 'max:50', 'in:' . implode(',', $hosts->availableCycles())],
        ]);

        try {
            $invoice = $hosts->renew($host, $data['billing_cycle'] ?? $host->billing_cycle ?? 'monthly');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'total' => (float) $invoice->total,
                'due_date' => $invoice->due_date?->toDateTimeString(),
            ],
        ], 201);
    }

    private function hostPayload(Host $host): array
    {
        return [
            'id' => $host->id,
            'domain' => $host->domain,
            'username' => $host->username,
            'status' => $host->status,
            'billing_cycle' => $host->billing_cycle,
            'first_payment_amount' => (float) $host->first_payment_amount,
            'recurring_amount' => (float) $host->recurring_amount,
            'next_due_date' => $host->next_due_date?->toDateTimeString(),
            'next_invoice_date' => $host->next_invoice_date?->toDateTimeString(),
            'product' => $host->product ? [
                'id' => $host->product->id,
                'name' => $host->product->name,
                'type' => $host->product->type,
            ] : null,
        ];
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 15), 1), 100);
    }
}
