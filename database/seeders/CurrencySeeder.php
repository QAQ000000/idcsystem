<?php

namespace Database\Seeders;

use App\Modules\Finance\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::query()->update(['is_default' => false]);

        foreach ($this->currencies() as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }

    private function currencies(): array
    {
        return [
            [
                'code' => 'CNY',
                'prefix' => '¥',
                'suffix' => '',
                'exchange_rate' => 1.0000,
                'is_default' => true,
            ],
            [
                'code' => 'USD',
                'prefix' => '$',
                'suffix' => '',
                'exchange_rate' => 7.2000,
                'is_default' => false,
            ],
            [
                'code' => 'EUR',
                'prefix' => '€',
                'suffix' => '',
                'exchange_rate' => 7.8000,
                'is_default' => false,
            ],
        ];
    }
}
