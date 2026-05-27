<?php

namespace App\Modules\User\Services;

use App\Modules\User\Models\Client;
use App\Modules\Finance\Models\Currency;
use App\Services\ClientActivityService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public const BLOCKING_HOST_STATUSES = ['Pending', 'Active', 'Suspended'];
    public const MAX_CREDIT_ADJUSTMENT_AMOUNT = 99999999.99;

    public function create(array $data): Client
    {
        $data['password'] = Hash::make($data['password']);
        $data['status'] = 0; // Inactive by default
        $data['currency_id'] ??= $this->defaultCurrencyId();

        return Client::create($data);
    }

    private function defaultCurrencyId(): int
    {
        return (int) (Currency::query()->where('is_default', true)->value('id')
            ?: Currency::query()->orderBy('id')->value('id')
            ?: 1);
    }

    public function update(Client $client, array $data): bool
    {
        if (array_key_exists('status', $data) && !in_array((int) $data['status'], [0, 1, 2], true)) {
            return false;
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return $client->update($data);
    }

    public function activate(Client $client): bool
    {
        return $client->update(['status' => 1]);
    }

    public function suspend(Client $client): bool
    {
        return $client->update(['status' => 2]);
    }

    public function hasBlockingHosts(Client $client): bool
    {
        return $client->hosts()
            ->whereIn('status', self::BLOCKING_HOST_STATUSES)
            ->exists();
    }

    public function delete(Client $client): bool
    {
        return DB::transaction(function () use ($client) {
            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->first();
            if (!$lockedClient || $this->hasBlockingHosts($lockedClient)) {
                return false;
            }

            return (bool) $lockedClient->delete();
        });
    }

    public function addCredit(Client $client, float $amount, string $description = ''): bool
    {
        $amount = $this->normalizeCreditAmount($amount);

        if ($amount === null) {
            return false;
        }

        $added = DB::transaction(function () use ($client, $amount, $description) {
            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->first();
            if (!$lockedClient || !$this->canAdjustCredit($lockedClient)) {
                return false;
            }

            $lockedClient->increment('credit', $amount);
            $lockedClient->refresh();

            \App\Modules\Finance\Models\Credit::create([
                'client_id'   => $lockedClient->id,
                'type'        => 'add',
                'amount'      => $amount,
                'balance'     => $lockedClient->credit,
                'description' => $description,
            ]);

            return true;
        });

        if ($added) {
            $freshClient = $client->fresh();
            app(ClientCacheService::class)->invalidateClient((int) $freshClient->id);
            app(ClientActivityService::class)->log($freshClient, 'credit.added', '账户余额已增加', [
                'amount' => $amount,
                'balance' => (float) $freshClient->credit,
                'description' => $description,
            ]);
        }

        return $added;
    }

    public function deductCredit(Client $client, float $amount, string $description = ''): bool
    {
        $amount = $this->normalizeCreditAmount($amount);

        if ($amount === null) {
            return false;
        }

        $deducted = DB::transaction(function () use ($client, $amount, $description) {
            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->first();
            if (!$lockedClient || !$this->canAdjustCredit($lockedClient) || !$this->canAfford($lockedClient, $amount)) {
                return false;
            }

            $lockedClient->decrement('credit', $amount);
            $lockedClient->refresh();

            \App\Modules\Finance\Models\Credit::create([
                'client_id'   => $lockedClient->id,
                'type'        => 'deduct',
                'amount'      => $amount,
                'balance'     => $lockedClient->credit,
                'description' => $description,
            ]);

            return true;
        });

        if ($deducted) {
            app(ClientCacheService::class)->invalidateClient((int) $client->id);
        }

        return $deducted;
    }

    /**
     * 检查客户余额加信用额度是否足够支付指定金额。
     */
    public function canAfford(Client $client, float $amount): bool
    {
        $amount = $this->normalizeCreditAmount($amount);

        if ($amount === null) {
            return false;
        }

        $available = round((float) $client->credit + (float) $client->credit_limit, 2);

        return $available >= $amount;
    }

    /**
     * 更新客户信用额度，信用额度不能为负数。
     */
    public function updateCreditLimit(Client $client, float $limit): bool
    {
        $limit = round($limit, 2);

        if ($limit < 0 || $limit > self::MAX_CREDIT_ADJUSTMENT_AMOUNT) {
            return false;
        }

        $updated = (bool) $client->update(['credit_limit' => $limit]);
        if ($updated) {
            app(ClientCacheService::class)->invalidateClient((int) $client->id);
        }

        return $updated;
    }

    private function canAdjustCredit(Client $client): bool
    {
        return !$client->trashed() && $client->isActive();
    }

    private function normalizeCreditAmount(float $amount): ?float
    {
        if ($amount <= 0 || $amount > self::MAX_CREDIT_ADJUSTMENT_AMOUNT) {
            return null;
        }

        return round($amount, 2);
    }
}
