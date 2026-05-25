<?php

namespace App\Modules\User\Services;

use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public const BLOCKING_HOST_STATUSES = ['Pending', 'Active', 'Suspended'];
    private const MAX_CREDIT_ADJUSTMENT_AMOUNT = 99999999.99;

    public function create(array $data): Client
    {
        $data['password'] = Hash::make($data['password']);
        $data['status'] = 0; // Inactive by default

        return Client::create($data);
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

        return DB::transaction(function () use ($client, $amount, $description) {
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
    }

    public function deductCredit(Client $client, float $amount, string $description = ''): bool
    {
        $amount = $this->normalizeCreditAmount($amount);

        if ($amount === null) {
            return false;
        }

        return DB::transaction(function () use ($client, $amount, $description) {
            $lockedClient = Client::query()->whereKey($client->id)->lockForUpdate()->first();
            if (!$lockedClient || !$this->canAdjustCredit($lockedClient) || !$lockedClient->hasEnoughCredit($amount)) {
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
