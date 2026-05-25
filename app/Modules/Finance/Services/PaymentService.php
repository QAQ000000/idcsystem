<?php

namespace App\Modules\Finance\Services;

use App\Models\PaymentRefundRequest;
use App\Models\PaymentAttempt;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Invoice;
use App\Plugins\Contracts\PaymentGatewayInterface;
use App\Plugins\Facades\Plugin;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    private InvoiceService $invoiceService;

    public function __construct(?InvoiceService $invoiceService = null)
    {
        $this->invoiceService = $invoiceService ?? new InvoiceService();
    }

    /**
     * 发起支付。
     */
    public function processPayment(Invoice $invoice, string $gateway, array $params): array
    {
        $invoice->loadMissing('client');
        if (!$invoice->client || $invoice->client->trashed() || !$invoice->client->isActive()) {
            return ['success' => false, 'message' => 'Client account is not payable'];
        }

        if ($invoice->status !== 'Unpaid') {
            return ['success' => false, 'message' => 'Invoice is not payable'];
        }

        $invoice->loadMissing('order');
        if ($invoice->order && $invoice->order->status !== 'Pending') {
            return ['success' => false, 'message' => 'Order is not payable'];
        }

        if ((float) $invoice->total <= 0) {
            return ['success' => false, 'message' => 'Invoice amount must be greater than zero'];
        }

        $plugin = $this->gateway($gateway);
        if (!$plugin) {
            return ['success' => false, 'message' => 'Payment gateway unavailable'];
        }

        $reservation = DB::transaction(function () use ($invoice, $gateway, $params) {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->with(['client', 'order'])->lockForUpdate()->first();
            if (!$lockedInvoice || $lockedInvoice->status !== 'Unpaid') {
                return ['result' => ['success' => false, 'message' => 'Invoice is not payable']];
            }

            if (!$lockedInvoice->client || $lockedInvoice->client->trashed() || !$lockedInvoice->client->isActive()) {
                return ['result' => ['success' => false, 'message' => 'Client account is not payable']];
            }

            if ($lockedInvoice->order && $lockedInvoice->order->status !== 'Pending') {
                return ['result' => ['success' => false, 'message' => 'Order is not payable']];
            }

            if ((float) $lockedInvoice->total <= 0) {
                return ['result' => ['success' => false, 'message' => 'Invoice amount must be greater than zero']];
            }

            $attempt = PaymentAttempt::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->where('gateway', $gateway)
                ->where('status', 'pending')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($attempt) {
                if (round((float) $attempt->amount, 2) !== round((float) $lockedInvoice->total, 2)) {
                    $attempt->update([
                        'status' => 'expired',
                        'result' => array_merge($attempt->result ?? [], [
                            'expired_reason' => 'Invoice amount changed',
                            'current_amount' => (float) $lockedInvoice->total,
                        ]),
                    ]);
                } elseif (is_array($attempt->result)) {
                    return ['result' => $attempt->result + ['reused' => true]];
                } else {
                    return ['result' => ['success' => false, 'message' => 'Payment is already being processed', 'processing' => true]];
                }
            }

            PaymentAttempt::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->where('gateway', $gateway)
                ->where('status', 'failed')
                ->update(['status' => 'expired']);

            $attempt = PaymentAttempt::query()->create([
                'invoice_id' => $lockedInvoice->id,
                'client_id' => $lockedInvoice->client_id,
                'gateway' => $gateway,
                'amount' => $lockedInvoice->total,
                'status' => 'pending',
            ]);

            return [
                'attempt' => $attempt->id,
                'payment' => [
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_number' => $lockedInvoice->invoice_number,
                    'amount' => (float) $lockedInvoice->total,
                    'client_id' => $lockedInvoice->client_id,
                    'params' => $params,
                ],
            ];
        });

        if (isset($reservation['result'])) {
            return $reservation['result'];
        }

        $attempt = PaymentAttempt::query()->find((int) ($reservation['attempt'] ?? 0));
        if (!$attempt) {
            return ['success' => false, 'message' => 'Payment attempt unavailable'];
        }

        $result = $plugin->pay($reservation['payment']);

        $attempt->update([
            'result' => $result,
            'status' => ($result['success'] ?? false) ? 'pending' : 'failed',
        ]);

        return $result;
    }

    /**
     * 处理支付回调。
     */
    public function handleCallback(string $gateway, array $data): bool
    {
        $plugin = $this->gateway($gateway);
        if (!$plugin || !$plugin->notify($data)) {
            return false;
        }

        if (!$this->callbackStatusIsPaid($data)) {
            return false;
        }

        $invoiceIdValue = $this->scalarCallbackValue($data, ['invoice_id', 'out_trade_no']);
        if ($invoiceIdValue === null || !ctype_digit($invoiceIdValue)) {
            return false;
        }

        $invoiceId = (int) $invoiceIdValue;
        $attempt = PaymentAttempt::query()
            ->where('invoice_id', $invoiceId)
            ->where('gateway', $gateway)
            ->where('status', 'pending')
            ->first();
        if (!$attempt) {
            return false;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if (!$invoice || $invoice->status === 'Paid') {
            return false;
        }

        $amountValue = $this->scalarCallbackValue($data, ['amount', 'total_amount']);
        if ($amountValue === null || !is_numeric($amountValue)) {
            return false;
        }

        $paidAmount = round((float) $amountValue, 2);
        if ($paidAmount !== round((float) $invoice->total, 2)) {
            return false;
        }

        $transId = $this->scalarCallbackValue($data, ['trans_id', 'trade_no', 'transaction_id']);
        if ($transId === null || $transId === '') {
            return false;
        }

        $paid = $this->invoiceService->markAsPaid($invoice, $gateway, $transId);

        if ($paid) {
            $attempt->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }

        return $paid;
    }

    private function scalarCallbackValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (!is_scalar($value)) {
                return null;
            }

            return trim((string) $value);
        }

        return null;
    }

    private function callbackStatusIsPaid(array $data): bool
    {
        $status = $this->scalarCallbackValue($data, ['status', 'payment_status', 'trade_status']);
        if ($status === null || $status === '') {
            return false;
        }

        return in_array(strtolower($status), [
            'paid',
            'success',
            'succeeded',
            'completed',
            'trade_success',
            'trade_finished',
        ], true);
    }

    /**
     * 原路退款。
     */
    public function refund(Account $account, float $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        if ($account->type !== 'credit') {
            return false;
        }

        $refundRequest = $this->createRefundRequest($account, $amount);
        if (!$refundRequest) {
            return false;
        }

        if (!$refundRequest->gateway_refund_succeeded_at) {
            $plugin = $this->gateway((string) $refundRequest->gateway);
            if (!$plugin) {
                $this->failRefundRequest($refundRequest, '支付网关不可用');

                return false;
            }

            if (!$plugin->refund((string) $refundRequest->gateway_trans_id, $amount)) {
                $this->failRefundRequest($refundRequest, '网关退款失败');

                return false;
            }

            $refundRequest->update(['gateway_refund_succeeded_at' => now()]);
        }

        $refundRequest->refresh();

        $refunded = DB::transaction(function () use ($refundRequest, $amount) {
            $lockedRequest = PaymentRefundRequest::query()->whereKey($refundRequest->id)->lockForUpdate()->first();
            if (!$lockedRequest || $lockedRequest->status === 'succeeded') {
                return $lockedRequest?->status === 'succeeded';
            }

            $lockedAccount = Account::query()->whereKey($lockedRequest->account_id)->lockForUpdate()->first();
            if (!$lockedAccount) {
                return false;
            }

            $refundedTotal = $this->refundedAmountForAccount($lockedAccount);
            $newRefundedTotal = round($refundedTotal + $amount, 2);
            if ($newRefundedTotal > round((float) $lockedAccount->amount, 2)) {
                return false;
            }

            if ($lockedAccount->invoice && !$this->invoiceService->refund($lockedAccount->invoice, $amount)) {
                return false;
            }

            $lockedAccount->update([
                'refunded' => $newRefundedTotal >= round((float) $lockedAccount->amount, 2) ? 1 : 0,
            ]);
            $lockedRequest->update([
                'status' => 'succeeded',
                'error' => null,
                'finished_at' => now(),
            ]);

            return true;
        });

        if (!$refunded) {
            $this->failRefundRequest($refundRequest, '网关退款已成功，但本地退款落库失败');
        }

        return $refunded;
    }

    private function createRefundRequest(Account $account, float $amount): ?PaymentRefundRequest
    {
        return DB::transaction(function () use ($account, $amount) {
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->first();
            if (!$lockedAccount) {
                return null;
            }

            if ($lockedAccount->type !== 'credit') {
                return null;
            }

            $remainingAccountRefundable = round((float) $lockedAccount->amount - $this->refundedAmountForAccount($lockedAccount), 2);
            if ($amount > $remainingAccountRefundable) {
                return null;
            }

            $pending = PaymentRefundRequest::query()
                ->where('account_id', $lockedAccount->id)
                ->where('gateway_trans_id', $lockedAccount->gateway_trans_id)
                ->where('amount', round($amount, 2))
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if ($pending) {
                return null;
            }

            $failed = PaymentRefundRequest::query()
                ->where('account_id', $lockedAccount->id)
                ->where('gateway_trans_id', $lockedAccount->gateway_trans_id)
                ->where('amount', round($amount, 2))
                ->where('status', 'failed')
                ->lockForUpdate()
                ->first();
            if ($failed) {
                if ($failed->gateway_refund_succeeded_at) {
                    return $failed;
                }

                if ($lockedAccount->invoice && !$this->invoiceService->canRefund($lockedAccount->invoice, $amount)) {
                    return null;
                }

                return $failed;
            }

            if ($lockedAccount->invoice && !$this->invoiceService->canRefund($lockedAccount->invoice, $amount)) {
                return null;
            }

            return PaymentRefundRequest::query()->create([
                'account_id' => $lockedAccount->id,
                'invoice_id' => (int) $lockedAccount->invoice_id,
                'gateway' => $lockedAccount->payment_method,
                'gateway_trans_id' => $lockedAccount->gateway_trans_id,
                'amount' => $amount,
                'status' => 'pending',
            ]);
        });
    }

    private function refundedAmountForAccount(Account $account): float
    {
        return round((float) PaymentRefundRequest::query()
            ->where('account_id', $account->id)
            ->where('gateway_trans_id', $account->gateway_trans_id)
            ->where('status', 'succeeded')
            ->sum('amount'), 2);
    }

    private function failRefundRequest(PaymentRefundRequest $refundRequest, string $error): void
    {
        $refundRequest->update([
            'status' => 'failed',
            'error' => $error,
            'finished_at' => now(),
        ]);
    }

    private function gateway(string $gateway): ?PaymentGatewayInterface
    {
        $plugin = Plugin::get($gateway);

        return $plugin instanceof PaymentGatewayInterface ? $plugin : null;
    }
}
