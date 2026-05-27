<?php

namespace App\Http\Controllers\Api;

use App\Modules\Finance\Services\InvoiceService;
use App\Modules\User\Services\ClientCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends ApiController
{
    /**
     * 获取账户资料。
     *
     * @response 200 {"success":true,"data":{"id":1,"username":"demo","credit":10}}
     */
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
        ] + app(ClientCacheService::class)->getCreditSummary((int) $client->id));
    }

    /**
     * 获取余额和信用额度。
     *
     * @response 200 {"success":true,"data":{"credit":10,"credit_limit":100,"available_credit":110}}
     */
    public function credit(Request $request): JsonResponse
    {
        return $this->success(app(ClientCacheService::class)->getCreditSummary((int) $request->user()->id));
    }

    /**
     * 创建账户充值账单。
     *
     * @response 201 {"success":true,"data":{"invoice_id":1,"invoice_number":"INV-202605260001","amount":100,"pay_url":"https://example.com/client/invoices/1"}}
     */
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
