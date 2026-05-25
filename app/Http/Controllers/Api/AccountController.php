<?php

namespace App\Http\Controllers\Api;

use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $client = $request->user();

        return $this->success([
            'id' => $client->id,
            'username' => $client->username,
            'email' => $client->email,
            'company_name' => $client->company_name,
            'phone' => $client->phone,
            'status' => $client->status,
            'currency_id' => $client->currency_id,
            'credit' => (float) $client->credit,
            'credit_limit' => (float) $client->credit_limit,
            'available_credit' => $client->availableCredit(),
        ]);
    }

    public function credit(Request $request): JsonResponse
    {
        $client = $request->user();

        return $this->success([
            'credit' => (float) $client->credit,
            'credit_limit' => (float) $client->credit_limit,
            'available_credit' => $client->availableCredit(),
            'debt' => max(0, abs(min(0, (float) $client->credit))),
        ]);
    }

    public function recharge(Request $request, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999.99'],
        ]);

        $invoice = $invoices->generateRecharge($request->user(), (float) $data['amount']);

        return $this->success([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount' => (float) $invoice->total,
            'pay_url' => url("/client/invoices/{$invoice->id}"),
        ], 201);
    }
}
