<?php

namespace App\Modules\User\Services;

use App\Modules\User\Models\Client;
use App\Services\Cache\CacheService;

class ClientCacheService extends CacheService
{
    protected string $prefix = 'client';

    protected int $ttl = 300;

    public function getCreditSummary(int $clientId): array
    {
        return $this->remember("credit-summary:{$clientId}", function () use ($clientId): array {
            $client = Client::query()->find($clientId);
            if (!$client) {
                return [
                    'credit' => 0.0,
                    'credit_limit' => 0.0,
                    'available_credit' => 0.0,
                    'debt' => 0.0,
                ];
            }

            $credit = (float) $client->credit;
            $creditLimit = (float) $client->credit_limit;

            return [
                'credit' => $credit,
                'credit_limit' => $creditLimit,
                'available_credit' => round($credit + $creditLimit, 2),
                'debt' => max(0, abs(min(0, $credit))),
            ];
        });
    }

    public function invalidateClient(int $clientId): void
    {
        $this->forget("credit-summary:{$clientId}");
    }
}
