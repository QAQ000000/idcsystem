<?php

use App\Plugins\Core\PluginManager;
use Carbon\Carbon;
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

if (! function_exists('current_timezone')) {
    function current_timezone(): string
    {
        $timezone = config('app.user_timezone')
            ?? auth('client')->user()?->timezone
            ?? auth('admin')->user()?->timezone
            ?? config('app.timezone', 'UTC');

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }
}

if (! function_exists('userTime')) {
    function userTime($datetime, string $format = 'Y-m-d H:i:s'): ?string
    {
        if (!$datetime) {
            return null;
        }

        return Carbon::parse($datetime)->timezone(current_timezone())->format($format);
    }
}

if (! function_exists('toUtc')) {
    function toUtc($datetime): ?Carbon
    {
        if (!$datetime) {
            return null;
        }

        return Carbon::parse($datetime, current_timezone())->timezone('UTC');
    }
}

if (! function_exists('timezones')) {
    function timezones(): array
    {
        return [
            'Asia/Shanghai' => '(UTC+8) 北京 / 上海',
            'Asia/Hong_Kong' => '(UTC+8) 香港',
            'Asia/Singapore' => '(UTC+8) 新加坡',
            'Asia/Tokyo' => '(UTC+9) 东京',
            'Asia/Seoul' => '(UTC+9) 首尔',
            'Europe/London' => '(UTC+0) 伦敦',
            'Europe/Paris' => '(UTC+1) 巴黎',
            'America/New_York' => '(UTC-5) 纽约',
            'America/Los_Angeles' => '(UTC-8) 洛杉矶',
            'Australia/Sydney' => '(UTC+10) 悉尼',
            'UTC' => '(UTC+0) UTC',
        ];
    }
}
