<?php

namespace App\Modules\User\Services;

use App\Modules\User\Models\Client;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public function create(array $data): Client
    {
        $data['password'] = Hash::make($data['password']);
        $data['status'] = 0; // Inactive by default

        return Client::create($data);
    }

    public function update(Client $client, array $data): bool
    {
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

    public function addCredit(Client $client, float $amount, string $description = ''): bool
    {
        return DB::transaction(function () use ($client, $amount, $description) {
            $client->increment('credit', $amount);

            \App\Modules\Finance\Models\Credit::create([
                'client_id'   => $client->id,
                'type'        => 'add',
                'amount'      => $amount,
                'balance'     => $client->fresh()->credit,
                'description' => $description,
            ]);

            return true;
        });
    }

    public function deductCredit(Client $client, float $amount, string $description = ''): bool
    {
        if (!$client->hasEnoughCredit($amount)) {
            return false;
        }

        return DB::transaction(function () use ($client, $amount, $description) {
            $client->decrement('credit', $amount);

            \App\Modules\Finance\Models\Credit::create([
                'client_id'   => $client->id,
                'type'        => 'deduct',
                'amount'      => $amount,
                'balance'     => $client->fresh()->credit,
                'description' => $description,
            ]);

            return true;
        });
    }
}