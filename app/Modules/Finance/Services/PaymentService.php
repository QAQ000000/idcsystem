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
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if (!$lockedInvoice || $lockedInvoice->status !== 'Unpaid') {
                return ['result' => ['success' => false, 'message' => 'Invoice is not payable']];
            }

            $attempt = PaymentAttempt::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->where('gateway', $gateway)
                ->whereIn('status', ['pending', 'failed'])
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($attempt && is_array($attempt->result)) {
                return ['result' => $attempt->result + ['reused' => true]];
            }

            if ($attempt) {
                return ['result' => ['success' => false, 'message' => 'Payment is already being processed', 'processing' => true]];
            }

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

        $invoiceId = (int) ($data['invoice_id'] ?? $data['out_trade_no'] ?? 0);
        $invoice = Invoice::query()->find($invoiceId);
        if (!$invoice || $invoice->status === 'Paid') {
            return false;
        }

        $invoice->loadMissing('order');
        if ($invoice->order && $invoice->order->status !== 'Pending') {
            return false;
        }

        $paidAmount = round((float) ($data['amount'] ?? $data['total_amount'] ?? 0), 2);
        if ($paidAmount !== round((float) $invoice->total, 2)) {
            return false;
        }

        $transId = (string) ($data['trans_id'] ?? $data['trade_no'] ?? $data['transaction_id'] ?? '');
        if ($transId === '') {
            return false;
        }

        $paid = $this->invoiceService->markAsPaid($invoice, $gateway, $transId);

        if ($paid) {
            PaymentAttempt::query()
                ->where('invoice_id', $invoice->id)
                ->where('gateway', $gateway)
                ->where('status', 'pending')
                ->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
        }

        return $paid;
    }

    /**
     * 原路退款。
     */
    public function refund(Account $account, float $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $refundRequest = $this->createRefundRequest($account, $amount);
        if (!$refundRequest) {
            return false;
        }

        if (!$refundRequest->gateway_refund_succeeded_at) {
            $plugin = $this->gateway((string) $refundRequest->gateway);
            if ($plugin && !$plugin->refund((string) $refundRequest->gateway_trans_id, $amount)) {
                $this->failRefundRequest($refundRequest, '网关退款失败');

                return false;
            }

            $refundRequest->update(['gateway_refund_succeeded_at' => now()]);
        }

        $refundRequest->refresh();

        $refunded = DB::transaction(function () use ($refundRequest, $amount) {
            $lockedRequest = PaymentRefundRequest::query()->whereKey($refundRequest->id)->lockForUpdate()->first();
            $lockedAccount = Account::query()->whereKey($lockedRequest->account_id)->lockForUpdate()->first();
            if (!$lockedAccount || $lockedAccount->refunded) {
                return false;
            }

            if ($lockedAccount->invoice && !$this->invoiceService->refund($lockedAccount->invoice, $amount)) {
                return false;
            }

            $lockedAccount->update(['refunded' => 1]);
            $lockedRequest->update([
                'status' => 'succeeded',
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
            if (!$lockedAccount || $amount > (float) $lockedAccount->amount || $lockedAccount->refunded) {
                return null;
            }

            $existing = PaymentRefundRequest::query()
                ->where('account_id', $lockedAccount->id)
                ->where('gateway_trans_id', $lockedAccount->gateway_trans_id)
                ->where('amount', round($amount, 2))
                ->whereIn('status', ['pending', 'succeeded', 'failed'])
                ->orderByRaw("case when status = 'pending' then 0 when status = 'succeeded' then 1 else 2 end")
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing->gateway_refund_succeeded_at ? $existing : null;
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
