<?php

namespace App\Modules\Product\Services;

use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use InvalidArgumentException;

class PricingService
{
    public const MAX_PRICE_AMOUNT = 99999999.99;

    private const PRICE_FIELDS = [
        'monthly',
        'monthly_setup',
        'quarterly',
        'quarterly_setup',
        'semiannually',
        'semiannually_setup',
        'annually',
        'annually_setup',
        'biennially',
        'biennially_setup',
        'triennially',
        'triennially_setup',
        'onetime',
        'hourly',
        'daily',
    ];

    /**
     * 设置指定对象在指定货币下的价格。
     */
    public function setPricing(string $type, int $relId, int $currencyId, array $prices): Pricing
    {
        $payload = $this->normalizePricePayload(
            array_intersect_key($prices, array_flip(self::PRICE_FIELDS))
        );

        $pricing = Pricing::updateOrCreate(
            [
                'type' => $type,
                'rel_id' => $relId,
                'currency_id' => $currencyId,
            ],
            $payload
        );

        if ($type === 'product') {
            app(ProductCacheService::class)->invalidateProduct($relId);
        }

        return $pricing;
    }

    /**
     * 获取指定对象在指定货币下的价格。
     */
    public function getPricing(string $type, int $relId, int $currencyId): ?Pricing
    {
        return Pricing::query()
            ->where('type', $type)
            ->where('rel_id', $relId)
            ->where('currency_id', $currencyId)
            ->first();
    }

    /**
     * 计算产品价格，包含产品价格、安装费和可选配置项价格。
     */
    public function calculatePrice(
        Product $product,
        string $billingCycle,
        array $configOptions = [],
        ?int $currencyId = null
    ): float
    {
        $currencyId = (int) ($currencyId ?? $configOptions['currency_id'] ?? $this->defaultCurrencyId());
        $pricing = $this->getPricing('product', (int) $product->id, $currencyId);

        if (!$pricing) {
            return 0.0;
        }

        $base = $this->cycleAmount($pricing, $billingCycle);
        if ($base < 0) {
            return 0.0;
        }

        $total = $base + $this->setupAmount($pricing, $billingCycle);

        foreach ($configOptions['options'] ?? [] as $option) {
            $optionPricing = null;
            if (isset($option['pricing']) && $option['pricing'] instanceof Pricing) {
                $optionPricing = $option['pricing'];
            } elseif (isset($option['rel_id'])) {
                $optionPricing = $this->getPricing('configoption', (int) $option['rel_id'], $currencyId);
            }

            if (!$optionPricing) {
                continue;
            }

            $quantity = max(1, (int) ($option['qty'] ?? 1));
            $optionAmount = $this->cycleAmount($optionPricing, $billingCycle);
            if ($optionAmount >= 0) {
                $total += $optionAmount * $quantity;
            }
        }

        return round($total, 2);
    }

    private function normalizePricePayload(array $prices): array
    {
        $payload = [];

        foreach ($prices as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                throw new InvalidArgumentException('价格字段必须是数字。');
            }

            $amount = round((float) $value, 2);
            $isSetupFee = str_ends_with((string) $field, '_setup');

            if ($isSetupFee && $amount < 0) {
                throw new InvalidArgumentException('安装费不能为负数。');
            }

            if (!$isSetupFee && $amount < -1) {
                throw new InvalidArgumentException('价格不能小于 -1。');
            }

            if ($amount > self::MAX_PRICE_AMOUNT) {
                throw new InvalidArgumentException('价格超出允许范围。');
            }

            $payload[$field] = $amount;
        }

        return $payload;
    }

    /**
     * 获取默认货币 ID。
     */
    public function defaultCurrencyId(): int
    {
        return (int) (Currency::query()->where('is_default', true)->value('id') ?: 1);
    }

    private function cycleAmount(Pricing $pricing, string $billingCycle): float
    {
        $field = $this->normalizeCycle($billingCycle);

        return (float) ($pricing->{$field} ?? -1);
    }

    private function setupAmount(Pricing $pricing, string $billingCycle): float
    {
        $field = $this->normalizeCycle($billingCycle) . '_setup';
        if (!in_array($field, self::PRICE_FIELDS, true)) {
            return 0.0;
        }

        return max(0.0, (float) ($pricing->{$field} ?? 0));
    }

    private function normalizeCycle(string $billingCycle): string
    {
        return match (strtolower($billingCycle)) {
            'month', 'monthly' => 'monthly',
            'quarter', 'quarterly' => 'quarterly',
            'semiannual', 'semiannually', 'semi-annually' => 'semiannually',
            'annual', 'annually', 'year', 'yearly' => 'annually',
            'biennial', 'biennially' => 'biennially',
            'triennial', 'triennially' => 'triennially',
            'one_time', 'one-time', 'onetime' => 'onetime',
            'hour', 'hourly' => 'hourly',
            'day', 'daily' => 'daily',
            default => strtolower($billingCycle),
        };
    }
}
