<?php

namespace Plugins\Gateway\AlipaySdk\src;

use App\Modules\Finance\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotifyController
{
    public function handle(Request $request, PaymentService $payment): Response
    {
        $handled = $payment->handleCallback('alipay_sdk', $this->normalized($request->all()));

        return response($handled ? 'success' : 'fail', $handled ? 200 : 400);
    }

    public function returnHandle(Request $request): RedirectResponse
    {
        $invoiceId = $request->input('out_trade_no');
        if (is_scalar($invoiceId) && ctype_digit((string) $invoiceId)) {
            return redirect()->route('client.invoices.show', (int) $invoiceId);
        }

        return redirect()->route('client.dashboard');
    }

    private function normalized(array $data): array
    {
        return [
            'invoice_id' => $data['out_trade_no'] ?? null,
            'trans_id' => $data['trade_no'] ?? null,
            'amount' => $data['total_amount'] ?? null,
            'status' => strtolower((string) ($data['trade_status'] ?? '')),
        ] + $data;
    }
}
