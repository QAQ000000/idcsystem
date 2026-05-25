<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Currency;
use Illuminate\Support\Collection;

class CurrencyService
{
    public function all(): Collection
    {
        return Currency::query()
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();
    }

    public function default(): Currency
    {
        return Currency::query()->where('is_default', true)->first()
            ?? Currency::query()->orderBy('id')->firstOrFail();
    }

    public function find(string $code): ?Currency
    {
        return Currency::query()->where('code', strtoupper(trim($code)))->first();
    }

    public function convert(float $amount, Currency $from, Currency $to): float
    {
        $fromRate = (float) $from->exchange_rate;
        $toRate = (float) $to->exchange_rate;
        if ($amount <= 0 || $fromRate <= 0 || $toRate <= 0) {
            return 0.0;
        }

        return round(($amount * $fromRate) / $toRate, 2);
    }

    public function format(float $amount, Currency $currency): string
    {
        return trim($currency->prefix . ' ' . number_format($amount, 2) . ($currency->suffix ? ' ' . $currency->suffix : ''));
    }
}
