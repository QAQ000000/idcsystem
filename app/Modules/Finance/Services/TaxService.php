<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\TaxRule;
use App\Modules\User\Models\Client;
use Illuminate\Support\Str;

class TaxService
{
    public function calculate(Client $client, float $subtotal): float
    {
        return round($subtotal * ($this->getRate($client) / 100), 2);
    }

    public function getRate(Client $client): float
    {
        return (float) ($this->getRule($client)?->rate ?? config('billing.tax_rate', 0));
    }

    public function getRule(Client $client): ?TaxRule
    {
        $countryCode = $this->normalizeCountryCode($client->country_code);
        if ($countryCode === null) {
            return null;
        }

        $stateCode = $this->normalizeStateCode($client->state_code);

        // 州/省规则优先，其次国家级规则；没有命中时回退全局税率配置。
        return TaxRule::query()
            ->where('country_code', $countryCode)
            ->where('active', true)
            ->where(function ($query) use ($stateCode): void {
                $query->whereNull('state_code');
                if ($stateCode !== null) {
                    $query->orWhere('state_code', $stateCode);
                }
            })
            ->orderByRaw('CASE WHEN state_code IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')
            ->first();
    }

    public function normalizeCountryCode(mixed $value): ?string
    {
        $value = Str::upper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    public function normalizeStateCode(mixed $value): ?string
    {
        $value = Str::upper(trim((string) $value));

        return preg_match('/^[A-Z0-9_-]{1,10}$/', $value) === 1 ? $value : null;
    }
}
