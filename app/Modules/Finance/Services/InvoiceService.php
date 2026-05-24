<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    /**
     * 生成账单和账单明细。
     */
    public function generate(Client $client, array $items): Invoice
    {
        return DB::transaction(function () use ($client, $items) {
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
        return DB::transaction(function () use ($invoice, $paymentMethod, $transId) {
            if ($invoice->status === 'Paid') {
                return true;
            }

            $invoice->update([
                'status' => 'Paid',
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
            ]);

            Account::firstOrCreate(
                ['gateway_trans_id' => $transId],
                [
                    'client_id' => $invoice->client_id,
                    'invoice_id' => $invoice->id,
                    'type' => 'credit',
                    'amount' => $invoice->total,
                    'fee' => 0,
                    'payment_method' => $paymentMethod,
                    'description' => 'Invoice payment ' . $invoice->invoice_number,
                    'refunded' => 0,
                ]
            );

            if ($invoice->order && $invoice->order->status !== 'Paid') {
                $invoice->order->update([
                    'status' => 'Paid',
                    'payment_method' => $paymentMethod,
                    'paid_at' => now(),
                ]);
            }

            return true;
        });
    }

    /**
     * 账单退款。
     */
    public function refund(Invoice $invoice, float $amount): bool
    {
        if ($amount <= 0 || $amount > (float) $invoice->total) {
            return false;
        }

        return DB::transaction(function () use ($invoice, $amount) {
            $invoice->accounts()->where('refunded', 0)->update(['refunded' => 1]);
            $invoice->update(['status' => $amount >= (float) $invoice->total ? 'Refunded' : 'Paid']);

            Account::create([
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'type' => 'debit',
                'amount' => $amount,
                'fee' => 0,
                'payment_method' => $invoice->payment_method,
                'gateway_trans_id' => 'REFUND-' . Str::upper(Str::random(12)),
                'description' => 'Invoice refund ' . $invoice->invoice_number,
                'refunded' => 0,
            ]);

            return true;
        });
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $invoice->refresh();
        $subtotal = round((float) $invoice->items()->sum('amount'), 2);
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
}
