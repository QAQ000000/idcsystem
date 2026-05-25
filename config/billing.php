<?php

use App\Services\SettingsService;

$setting = static function (string $key, mixed $default): mixed {
    try {
        if (!class_exists(SettingsService::class)) {
            return $default;
        }

        return app(SettingsService::class)->get($key, $default);
    } catch (Throwable) {
        return $default;
    }
};

$legacySetting = static function (string $key, string $legacyKey, mixed $default) use ($setting): mixed {
    $value = $setting($key, null);

    return $value === null || $value === '' ? $setting($legacyKey, $default) : $value;
};

return [
    /*
     * 税率（百分比，0 表示不含税）。
     */
    'tax_rate' => (float) $setting('billing_tax_rate', env('BILLING_TAX_RATE', 0)),

    /*
     * 账单到期天数（从生成日起计算）。
     */
    'due_days' => (int) $legacySetting('billing_due_days', 'invoice_due_days', env('BILLING_DUE_DAYS', 7)),

    /*
     * 到期提醒提前天数。
     */
    'reminder_days' => (int) $legacySetting('billing_reminder_days', 'renewal_reminder_days', env('BILLING_REMINDER_DAYS', 7)),

    /*
     * 逾期宽限天数（到期后多少天才暂停服务）。
     */
    'grace_days' => (int) $setting('billing_grace_days', env('BILLING_GRACE_DAYS', 0)),

    /*
     * 自动续费账单提前生成天数（到期前多少天生成续费账单）。
     */
    'invoice_days_before_due' => (int) $setting(
        'billing_invoice_days_before_due',
        env('BILLING_INVOICE_DAYS_BEFORE_DUE', 7)
    ),
];
