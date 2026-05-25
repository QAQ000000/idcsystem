<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Contract;
use App\Modules\Finance\Models\ContractTemplate;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\DB;

class ContractService
{
    public function generate(Client $client, Order $order, ContractTemplate $template): Contract
    {
        return DB::transaction(function () use ($client, $order, $template) {
            $content = $this->renderTemplate($template->content, $client, $order);

            return Contract::query()->create([
                'client_id' => $client->id,
                'order_id' => $order->id,
                'title' => $template->name,
                'content' => $content,
                'status' => 'pending_signature',
            ]);
        });
    }

    public function sign(Contract $contract, string $ip): bool
    {
        return DB::transaction(function () use ($contract, $ip) {
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending_signature') {
                return false;
            }

            return (bool) $locked->update([
                'status' => 'signed',
                'signed_at' => now(),
                'sign_ip' => substr($ip, 0, 45),
            ]);
        });
    }

    public function cancel(Contract $contract): bool
    {
        return DB::transaction(function () use ($contract) {
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->first();
            if (!$locked || in_array($locked->status, ['signed', 'cancelled'], true)) {
                return false;
            }

            return (bool) $locked->update(['status' => 'cancelled']);
        });
    }

    private function renderTemplate(string $content, Client $client, Order $order): string
    {
        $variables = [
            'client_name' => $client->username,
            'client_email' => $client->email,
            'company_name' => $client->company_name ?: '',
            'order_id' => (string) $order->id,
            'order_number' => $order->order_number,
            'order_amount' => (string) $order->amount,
            'date' => now()->toDateString(),
        ];

        foreach ($variables as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }

        return $content;
    }
}
