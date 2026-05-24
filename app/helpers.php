<?php

use App\Plugins\Core\PluginManager;
use Illuminate\Support\Facades\Cache;

if (! function_exists('plugin')) {
    function plugin(?string $type = null): PluginManager
    {
        $manager = app(PluginManager::class);
        return $type ? $manager->type($type) : $manager;
    }
}

if (! function_exists('hook')) {
    function hook(string $event, array $data = []): void
    {
        app(\Illuminate\Contracts\Events\Dispatcher::class)
            ->dispatch('hook.' . $event, $data);
    }
}

if (! function_exists('system_setting')) {
    function system_setting(string $key, $default = null): mixed
    {
        return Cache::rememberForever('setting.' . $key, function () use ($key, $default) {
            return \App\Models\Setting::where('key', $key)->value('value') ?? $default;
        });
    }
}

if (! function_exists('format_amount')) {
    function format_amount(float $amount, string $currency = 'CNY'): string
    {
        return number_format($amount, 2) . ' ' . $currency;
    }
}