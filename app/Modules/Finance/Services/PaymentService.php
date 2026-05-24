<?php

namespace App\Modules\Finance\Services;

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
        $plugin = $this->gateway($gateway);
        if (!$plugin) {
            return ['success' => false, 'message' => 'Payment gateway unavailable'];
        }

        return $plugin->pay([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => (float) $invoice->total,
            'client_id' => $invoice->client_id,
            'params' => $params,
        ]);
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
        if (!$invoice) {
            return false;
        }

        $paidAmount = (float) ($data['amount'] ?? $data['total_amount'] ?? $invoice->total);
        if (round($paidAmount, 2) < round((float) $invoice->total, 2)) {
            return false;
        }

        $transId = (string) ($data['trans_id'] ?? $data['trade_no'] ?? $data['transaction_id'] ?? '');
        if ($transId === '') {
            return false;
        }

        return $this->invoiceService->markAsPaid($invoice, $gateway, $transId);
    }

    /**
     * 原路退款。
     */
    public function refund(Account $account, float $amount): bool
    {
        if ($amount <= 0 || $amount > (float) $account->amount || $account->refunded) {
            return false;
        }

        return DB::transaction(function () use ($account, $amount) {
            $plugin = $this->gateway((string) $account->payment_method);
            if ($plugin && !$plugin->refund((string) $account->gateway_trans_id, $amount)) {
                return false;
            }

            $account->update(['refunded' => 1]);

            if ($account->invoice) {
                $this->invoiceService->refund($account->invoice, $amount);
            }

            return true;
        });
    }

    private function gateway(string $gateway): ?PaymentGatewayInterface
    {
        $plugin = Plugin::get($gateway);

        return $plugin instanceof PaymentGatewayInterface ? $plugin : null;
    }
}
