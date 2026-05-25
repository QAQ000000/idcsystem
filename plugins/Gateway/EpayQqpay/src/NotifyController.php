<?php

namespace Plugins\Gateway\EpayQqpay\src;

use App\Modules\Finance\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotifyController
{
    public function handle(Request $request, PaymentService $payment): Response
    {
        $handled = $payment->handleCallback('epay_qqpay', $this->normalized($request->all()));
        return response($handled ? 'success' : 'fail', $handled ? 200 : 400);
    }

    public function returnHandle(Request $request): RedirectResponse
    {
        $invoiceId = $request->input('out_trade_no');
        return is_scalar($invoiceId) && ctype_digit((string) $invoiceId)
            ? redirect()->route('client.invoices.show', (int) $invoiceId)
            : redirect()->route('client.dashboard');
    }

    private function normalized(array $data): array
    {
        return ['invoice_id' => $data['out_trade_no'] ?? null, 'trans_id' => $data['trade_no'] ?? null, 'amount' => $data['money'] ?? null, 'status' => strtolower((string) ($data['trade_status'] ?? ''))] + $data;
    }
}
