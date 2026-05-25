<?php

namespace App\Http\Controllers\Api;

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
}
