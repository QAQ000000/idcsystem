<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'system:settings';

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()->get($key, $default);
    }

    public function set(string $key, mixed $value, string $group = 'general'): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $this->encode($value), 'group' => $group]
        );

        $this->clearCache();
    }

    public function setMany(array $settings, string $group): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value, $group);
        }
    }

    public function all(?string $group = null)
    {
        $cached = Cache::get(self::CACHE_KEY);

        // 旧版本曾把 Collection 对象写入缓存，部分缓存驱动反序列化后会变成 incomplete object。
        // 这里只信任普通数组，发现旧缓存就丢弃并从数据库重建，避免后台设置页被历史缓存打崩。
        if (!is_array($cached)) {
            Cache::forget(self::CACHE_KEY);
            $cached = $this->settingsArray();
            Cache::forever(self::CACHE_KEY, $cached);
        }

        $settings = collect($cached);

        if (!$group) {
            return $settings;
        }

        $keys = Setting::query()->where('group', $group)->pluck('key')->all();

        return $settings->only($keys);
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function encode(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    private function decode(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function settingsArray(): array
    {
        return Setting::query()
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $this->decode($setting->value)])
            ->all();
    }
}
