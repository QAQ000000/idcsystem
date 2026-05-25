<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Upgrade;
use App\Modules\Order\Services\HostService;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\ClientService;
use App\Services\Concerns\NotifiesClientsSafely;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class InvoiceService
{
    use NotifiesClientsSafely;

    public const MAX_AMOUNT = 99999999.99;

    /**
     * 生成账单和账单明细。
     */
    public function generate(Client $client, array $items): Invoice
    {
        $invoice = DB::transaction(function () use ($client, $items) {
            $this->assertClientCanReceiveInvoice($client);
            $this->validateInvoiceItems($items);
            $subtotal = round(array_sum(array_map(
                fn (array $item) => (float) ($item['amount'] ?? 0),
                $items
            )), 2);
            $taxRate = (float) config('billing.tax_rate', 0);
            $tax = round($subtotal * ($taxRate / 100), 2);
            $total = round($subtotal + $tax, 2);
            $this->assertAmountFitsStorage($subtotal);
            $this->assertAmountFitsStorage($tax);
            $this->assertAmountFitsStorage($total);

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'invoice_number' => $this->nextInvoiceNumber(),
                'subtotal' => $subtotal,
                'tax' => $tax,
                'tax_rate' => $taxRate,
                'credit_used' => 0,
                'total' => $total,
                'status' => 'Unpaid',
                'due_date' => now()->addDays((int) config('billing.due_days', 7)),
            ]);

            foreach ($items as $item) {
                $this->createItem(
                    $invoice,
                    (string) ($item['type'] ?? 'product'),
                    (string) ($item['description'] ?? ''),
                    (float) ($item['amount'] ?? 0),
                    (int) ($item['rel_id'] ?? 0),
                    is_array($item['meta'] ?? null) ? $item['meta'] : null,
                    true
                );
            }

            return $invoice->fresh(['items']);
        });

        $this->notifyClientSafely($client, 'invoice_created', [
            'client_name' => $client->username,
            'invoice_number' => $invoice->invoice_number,
            'amount' => $invoice->total,
        ], 'invoice.generate');

        return $invoice;
    }

    /**
     * 生成无需付款的 0 元账单，用于降配等已经即时生效的调整记录。
     */
    public function generateNoPaymentRequired(Client $client, array $items): Invoice
    {
        return DB::transaction(function () use ($client, $items) {
            $this->assertClientCanReceiveInvoice($client);
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
                $this->createItem(
                    $invoice,
                    (string) ($item['type'] ?? 'adjustment'),
                    (string) ($item['description'] ?? ''),
                    0,
                    (int) ($item['rel_id'] ?? 0),
                    is_array($item['meta'] ?? null) ? $item['meta'] : null,
                    true
                );
            }

            return $invoice->fresh(['items']);
        });
    }

    /**
     * 生成客户自助充值账单。
     */
    public function generateRecharge(Client $client, float $amount): Invoice
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('充值金额超出允许范围。');
        }

        return $this->generate($client, [[
            'type' => 'recharge',
            'description' => '账户充值',
            'amount' => $amount,
        ]]);
    }

    /**
     * 添加账单项目并同步账单金额。
     */
    public function addItem(
        Invoice $invoice,
        string $type,
        string $description,
        float $amount,
        int $relId = 0,
        ?array $meta = null
    ): InvoiceItem {
        return $this->createItem($invoice, $type, $description, $amount, $relId, $meta);
    }

    private function createItem(
        Invoice $invoice,
        string $type,
        string $description,
        float $amount,
        int $relId = 0,
        ?array $meta = null,
        bool $allowFinalizedInvoice = false
    ): InvoiceItem {
        return DB::transaction(function () use ($invoice, $type, $description, $amount, $relId, $meta, $allowFinalizedInvoice) {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if (!$lockedInvoice) {
                throw new InvalidArgumentException('账单不存在。');
            }

            if (!$allowFinalizedInvoice && $lockedInvoice->status !== 'Unpaid') {
                throw new InvalidArgumentException('只能给未支付账单追加明细。');
            }

            $this->assertAmountFitsStorage($amount);

            $item = InvoiceItem::create([
                'invoice_id' => $lockedInvoice->id,
                'type' => $type,
                'description' => $description,
                'amount' => round($amount, 2),
                'rel_id' => $relId,
                'meta' => $meta,
            ]);

            $this->recalculateTotals($lockedInvoice);

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
            $orderId = $invoice->order()->value('id');
            if ($orderId) {
                \App\Modules\Order\Models\Order::query()->whereKey($orderId)->lockForUpdate()->first();
            }

            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->with(['client', 'order'])->lockForUpdate()->first();
            if (!$lockedInvoice) {
                return false;
            }

            if (!$lockedInvoice->client || $lockedInvoice->client->trashed() || !$lockedInvoice->client->isActive()) {
                return false;
            }

            if ($lockedInvoice->status === 'Paid') {
                return false;
            }

            if ($lockedInvoice->status !== 'Unpaid') {
                return false;
            }

            if ((float) $lockedInvoice->total <= 0) {
                return false;
            }

            if (!$this->serviceItemsArePayable($lockedInvoice)) {
                return false;
            }

            if ($lockedInvoice->order
                && $lockedInvoice->order->status !== 'Pending'
                && !$this->isRenewalInvoice($lockedInvoice)) {
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
                $this->notifyClientSafely($freshInvoice->client, 'invoice_paid', [
                    'client_name' => $freshInvoice->client->username,
                    'invoice_number' => $freshInvoice->invoice_number,
                    'amount' => $freshInvoice->total,
                ], 'invoice.mark_paid');
            }
        }

        return $paid;
    }

    /**
     * 使用客户账户余额支付未付款账单。
     */
    public function payWithCredit(Invoice $invoice): bool
    {
        try {
            return DB::transaction(function () use ($invoice) {
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoice->id)
                    ->with('client')
                    ->lockForUpdate()
                    ->first();

                if (!$lockedInvoice || $lockedInvoice->status !== 'Unpaid' || (float) $lockedInvoice->total <= 0) {
                    return false;
                }

                $client = $lockedInvoice->client;
                if (!$client || $client->trashed() || !$client->isActive()) {
                    return false;
                }

                $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->first();
                if (!$lockedClient || !$lockedClient->hasEnoughCredit((float) $lockedInvoice->total)) {
                    return false;
                }

                $deducted = app(ClientService::class)->deductCredit(
                    $lockedClient,
                    (float) $lockedInvoice->total,
                    '余额支付：账单 ' . $lockedInvoice->invoice_number
                );

                if (!$deducted) {
                    return false;
                }

                $paid = $this->markAsPaid(
                    $lockedInvoice,
                    'credit',
                    'CREDIT-' . Str::upper(Str::random(16))
                );

                if (!$paid) {
                    throw new \RuntimeException('Credit payment could not mark invoice paid.');
                }

                return true;
            });
        } catch (Throwable) {
            return false;
        }
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
        $refunded = DB::transaction(function () use ($invoice, $amount) {
            $orderId = $invoice->order()->value('id');
            if ($orderId) {
                \App\Modules\Order\Models\Order::query()->whereKey($orderId)->lockForUpdate()->first();
            }

            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->with('order')->lockForUpdate()->first();
            if (!$this->isRefundable($lockedInvoice, $amount)) {
                return false;
            }

            $refundedTotal = $this->refundedAmount($lockedInvoice);
            $newRefundedTotal = round($refundedTotal + $amount, 2);
            $fullyRefunded = $newRefundedTotal >= round((float) $lockedInvoice->total, 2);

            $lockedInvoice->update([
                'status' => $fullyRefunded ? 'Refunded' : 'Partially Refunded',
            ]);

            if ($lockedInvoice->order) {
                $lockedInvoice->order->update([
                    'status' => $fullyRefunded ? 'Refunded' : 'Partially Refunded',
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

        if ($refunded) {
            $this->syncRefundedHosts($invoice->fresh(['order.hosts.product']));
        }

        return $refunded;
    }

    public function canRefund(Invoice $invoice, float $amount): bool
    {
        $freshInvoice = Invoice::query()->whereKey($invoice->id)->first();

        return $this->isRefundable($freshInvoice, $amount);
    }

    public function remainingRefundableAmount(Invoice $invoice): float
    {
        $freshInvoice = Invoice::query()->whereKey($invoice->id)->first();
        if (!$freshInvoice || !in_array($freshInvoice->status, ['Paid', 'Partially Refunded'], true)) {
            return 0.0;
        }

        return $this->calculateRemainingRefundableAmount($freshInvoice);
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $invoice->refresh();
        $subtotal = round((float) $invoice->items()->sum('amount'), 2);
        if ($subtotal < 0) {
            throw new InvalidArgumentException('账单金额不能为负数。');
        }

        $tax = round($subtotal * ((float) $invoice->tax_rate / 100), 2);
        $total = round($subtotal + $tax - (float) $invoice->credit_used, 2);
        $this->assertAmountFitsStorage($subtotal);
        $this->assertAmountFitsStorage($tax);
        $this->assertAmountFitsStorage($total);

        $invoice->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ]);
    }

    private function nextInvoiceNumber(): string
    {
        return 'INV' . now()->format('YmdHis') . Str::upper(Str::random(4));
    }

    private function isRefundable(?Invoice $invoice, float $amount): bool
    {
        if ($invoice === null || !in_array($invoice->status, ['Paid', 'Partially Refunded'], true) || $amount <= 0) {
            return false;
        }

        return $amount <= $this->calculateRemainingRefundableAmount($invoice);
    }

    private function calculateRemainingRefundableAmount(Invoice $invoice): float
    {
        return round(max(0, (float) $invoice->total - $this->refundedAmount($invoice)), 2);
    }

    private function refundedAmount(Invoice $invoice): float
    {
        return round((float) Account::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'debit')
            ->sum('amount'), 2);
    }

    private function isRenewalInvoice(Invoice $invoice): bool
    {
        $invoice->loadMissing('items');

        return $invoice->items->contains(fn (InvoiceItem $item) => $item->type === 'renewal');
    }

    /**
     * 支付前复查服务类账单，避免旧账单在服务终止或客户停用后继续生效。
     */
    private function serviceItemsArePayable(Invoice $invoice): bool
    {
        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            if ($item->type === 'renewal' && !$this->renewalItemIsPayable($invoice, $item)) {
                return false;
            }

            if ($item->type === 'upgrade' && !$this->upgradeItemIsPayable($invoice, $item)) {
                return false;
            }
        }

        return true;
    }

    private function renewalItemIsPayable(Invoice $invoice, InvoiceItem $item): bool
    {
        $host = Host::query()
            ->with('client')
            ->whereKey((int) $item->rel_id)
            ->lockForUpdate()
            ->first();

        if (!$host || (int) $host->client_id !== (int) $invoice->client_id) {
            return false;
        }

        if (!$host->client || $host->client->trashed() || !$host->client->isActive()) {
            return false;
        }

        return !in_array($host->status, ['Terminated', 'Cancelled'], true);
    }

    private function upgradeItemIsPayable(Invoice $invoice, InvoiceItem $item): bool
    {
        $upgrade = Upgrade::query()
            ->whereKey((int) $item->rel_id)
            ->lockForUpdate()
            ->first();
        if (!$upgrade || $upgrade->status !== 'Pending') {
            return false;
        }

        $host = Host::query()
            ->with('client')
            ->whereKey((int) $upgrade->host_id)
            ->lockForUpdate()
            ->first();

        if (!$host || (int) $host->client_id !== (int) $invoice->client_id) {
            return false;
        }

        if (!$host->client || $host->client->trashed() || !$host->client->isActive()) {
            return false;
        }

        return $host->status === 'Active';
    }

    private function syncRefundedHosts(?Invoice $invoice): void
    {
        if (!$invoice?->order) {
            return;
        }

        $hostService = app(HostService::class);
        foreach ($invoice->order->hosts as $host) {
            if ($invoice->status === 'Refunded') {
                if (in_array($host->status, ['Active', 'Suspended'], true)) {
                    if (!$hostService->terminate($host)) {
                        $host->update([
                            'admin_notes' => trim(($host->admin_notes ? $host->admin_notes . PHP_EOL : '') . '全额退款后服务终止失败，请人工处理'),
                        ]);
                        $host->actionLogs()->create([
                            'action' => 'refund_termination_pending',
                            'message' => '全额退款后服务终止失败，请人工处理',
                            'meta' => ['invoice_id' => $invoice->id],
                        ]);
                    }
                }

                continue;
            }

            if ($invoice->status === 'Partially Refunded') {
                $host->actionLogs()->create([
                    'action' => 'refund_partial',
                    'message' => '订单账单已部分退款，请人工确认服务是否需要调整',
                    'meta' => ['invoice_id' => $invoice->id],
                ]);
            }
        }
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

            $this->assertAmountFitsStorage((float) ($item['amount'] ?? 0));
        }
    }

    private function assertClientCanReceiveInvoice(Client $client): void
    {
        $freshClient = Client::query()->whereKey($client->id)->first();

        if (!$freshClient || $freshClient->trashed() || !$freshClient->isActive()) {
            throw new InvalidArgumentException('客户账号状态不允许生成账单。');
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

    private function assertAmountFitsStorage(float $amount): void
    {
        if ($amount < 0 || $amount > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('账单金额超出允许范围。');
        }
    }
}
