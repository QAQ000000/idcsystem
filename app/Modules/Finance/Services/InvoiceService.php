<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Services\HostService;
use App\Modules\User\Models\Client;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InvoiceService
{
    /**
     * 生成账单和账单明细。
     */
    public function generate(Client $client, array $items): Invoice
    {
        $invoice = DB::transaction(function () use ($client, $items) {
            $this->validateInvoiceItems($items);
            $subtotal = round(array_sum(array_map(
                fn (array $item) => (float) ($item['amount'] ?? 0),
                $items
            )), 2);
            $taxRate = (float) config('billing.tax_rate', 0);
            $tax = round($subtotal * ($taxRate / 100), 2);

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'invoice_number' => $this->nextInvoiceNumber(),
                'subtotal' => $subtotal,
                'tax' => $tax,
                'tax_rate' => $taxRate,
                'credit_used' => 0,
                'total' => round($subtotal + $tax, 2),
                'status' => 'Unpaid',
                'due_date' => now()->addDays((int) config('billing.due_days', 7)),
            ]);

            foreach ($items as $item) {
                $this->addItem(
                    $invoice,
                    (string) ($item['type'] ?? 'product'),
                    (string) ($item['description'] ?? ''),
                    (float) ($item['amount'] ?? 0),
                    (int) ($item['rel_id'] ?? 0)
                );
            }

            return $invoice->fresh(['items']);
        });

        app(NotificationService::class)->notifyClient($client, 'invoice_created', [
            'client_name' => $client->username,
            'invoice_number' => $invoice->invoice_number,
            'amount' => $invoice->total,
        ]);

        return $invoice;
    }

    /**
     * 生成无需付款的 0 元账单，用于降配等已经即时生效的调整记录。
     */
    public function generateNoPaymentRequired(Client $client, array $items): Invoice
    {
        return DB::transaction(function () use ($client, $items) {
            $this->validateNoPaymentItems($items);

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'invoice_number' => $this->nextInvoiceNumber(),
                'subtotal' => 0,
                'tax' => 0,
                'tax_rate' => (float) config('billing.tax_rate', 0),
                'credit_used' => 0,
                'total' => 0,
                'status' => 'Paid',
                'payment_method' => 'no_payment_required',
                'paid_at' => now(),
                'due_date' => now(),
            ]);

            foreach ($items as $item) {
                $this->addItem(
                    $invoice,
                    (string) ($item['type'] ?? 'adjustment'),
                    (string) ($item['description'] ?? ''),
                    0,
                    (int) ($item['rel_id'] ?? 0)
                );
            }

            return $invoice->fresh(['items']);
        });
    }

    /**
     * 添加账单项目并同步账单金额。
     */
    public function addItem(
        Invoice $invoice,
        string $type,
        string $description,
        float $amount,
        int $relId = 0
    ): InvoiceItem {
        return DB::transaction(function () use ($invoice, $type, $description, $amount, $relId) {
            $item = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'type' => $type,
                'description' => $description,
                'amount' => round($amount, 2),
                'rel_id' => $relId,
            ]);

            $this->recalculateTotals($invoice);

            return $item;
        });
    }

    /**
     * 计算账单税额。
     */
    public function calculateTax(Invoice $invoice): float
    {
        return round((float) $invoice->subtotal * ((float) $invoice->tax_rate / 100), 2);
    }

    /**
     * 标记账单已支付并记录交易流水。
     */
    public function markAsPaid(Invoice $invoice, string $paymentMethod, string $transId): bool
    {
        $paid = DB::transaction(function () use ($invoice, $paymentMethod, $transId) {
            $orderId = Invoice::query()->whereKey($invoice->id)->value('order_id');
            if ($orderId) {
                \App\Modules\Order\Models\Order::query()->whereKey($orderId)->lockForUpdate()->first();
            }

            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->with('order')->lockForUpdate()->first();
            if (!$lockedInvoice) {
                return false;
            }

            if ($lockedInvoice->status === 'Paid') {
                return false;
            }

            if ($lockedInvoice->status !== 'Unpaid') {
                return false;
            }

            if ($lockedInvoice->order && $lockedInvoice->order->status !== 'Pending') {
                return false;
            }

            if (Account::query()->where('gateway_trans_id', $transId)->exists()) {
                return false;
            }

            $lockedInvoice->update([
                'status' => 'Paid',
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
            ]);

            Account::query()->create([
                'client_id' => $lockedInvoice->client_id,
                'invoice_id' => $lockedInvoice->id,
                'type' => 'credit',
                'amount' => $lockedInvoice->total,
                'fee' => 0,
                'payment_method' => $paymentMethod,
                'gateway_trans_id' => $transId,
                'description' => 'Invoice payment ' . $lockedInvoice->invoice_number,
                'refunded' => 0,
            ]);

            if ($lockedInvoice->order && $lockedInvoice->order->status !== 'Paid') {
                $lockedInvoice->order->update([
                    'status' => 'Paid',
                    'payment_method' => $paymentMethod,
                    'paid_at' => now(),
                ]);
            }

            return true;
        });

        if ($paid) {
            $freshInvoice = $invoice->fresh(['order.hosts.product', 'items', 'client']);
            $this->provisionPendingOrderHosts($freshInvoice);
            app(HostService::class)->applyPaidInvoice($freshInvoice);
            if ($freshInvoice->client) {
                app(NotificationService::class)->notifyClient($freshInvoice->client, 'invoice_paid', [
                    'client_name' => $freshInvoice->client->username,
                    'invoice_number' => $freshInvoice->invoice_number,
                    'amount' => $freshInvoice->total,
                ]);
            }
        }

        return $paid;
    }

    private function provisionPendingOrderHosts(Invoice $invoice): void
    {
        if (!$invoice->order) {
            return;
        }

        $hosts = $invoice->order->hosts->where('status', 'Pending');
        if ($hosts->isEmpty()) {
            return;
        }

        $hostService = app(HostService::class);
        foreach ($hosts as $host) {
            $hostService->provision($host);
        }
    }

    /**
     * 账单退款。
     */
    public function refund(Invoice $invoice, float $amount): bool
    {
        return DB::transaction(function () use ($invoice, $amount) {
            $orderId = Invoice::query()->whereKey($invoice->id)->value('order_id');
            if ($orderId) {
                \App\Modules\Order\Models\Order::query()->whereKey($orderId)->lockForUpdate()->first();
            }

            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->with('order')->lockForUpdate()->first();
            if (!$this->isRefundable($lockedInvoice, $amount)) {
                return false;
            }

            $lockedInvoice->update([
                'status' => $amount >= (float) $lockedInvoice->total ? 'Refunded' : 'Partially Refunded',
            ]);

            if ($lockedInvoice->order) {
                $lockedInvoice->order->update([
                    'status' => $amount >= (float) $lockedInvoice->total ? 'Refunded' : 'Partially Refunded',
                ]);
            }

            Account::create([
                'client_id' => $lockedInvoice->client_id,
                'invoice_id' => $lockedInvoice->id,
                'type' => 'debit',
                'amount' => $amount,
                'fee' => 0,
                'payment_method' => $lockedInvoice->payment_method,
                'gateway_trans_id' => 'REFUND-' . Str::upper(Str::random(12)),
                'description' => 'Invoice refund ' . $lockedInvoice->invoice_number,
                'refunded' => 0,
            ]);

            return true;
        });
    }

    public function canRefund(Invoice $invoice, float $amount): bool
    {
        $freshInvoice = Invoice::query()->whereKey($invoice->id)->first();

        return $this->isRefundable($freshInvoice, $amount);
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $invoice->refresh();
        $subtotal = round((float) $invoice->items()->sum('amount'), 2);
        if ($subtotal < 0) {
            throw new InvalidArgumentException('账单金额不能为负数。');
        }

        $tax = round($subtotal * ((float) $invoice->tax_rate / 100), 2);
        $invoice->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax - (float) $invoice->credit_used, 2),
        ]);
    }

    private function nextInvoiceNumber(): string
    {
        return 'INV' . now()->format('YmdHis') . Str::upper(Str::random(4));
    }

    private function isRefundable(?Invoice $invoice, float $amount): bool
    {
        return $invoice !== null
            && $invoice->status === 'Paid'
            && $amount > 0
            && $amount <= (float) $invoice->total;
    }

    private function validateInvoiceItems(array $items): void
    {
        if ($items === []) {
            throw new InvalidArgumentException('账单明细不能为空。');
        }

        foreach ($items as $item) {
            if ((float) ($item['amount'] ?? 0) <= 0) {
                throw new InvalidArgumentException('账单明细金额必须大于 0。');
            }
        }
    }

    private function validateNoPaymentItems(array $items): void
    {
        if ($items === []) {
            throw new InvalidArgumentException('账单明细不能为空。');
        }

        foreach ($items as $item) {
            if (round((float) ($item['amount'] ?? 0), 2) !== 0.0) {
                throw new InvalidArgumentException('无需付款账单明细金额必须为 0。');
            }
        }
    }
}
