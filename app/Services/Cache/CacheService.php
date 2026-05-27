<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

abstract class CacheService
{
    protected string $prefix;

    protected int $ttl = 3600;

    protected function key(string $suffix): string
    {
        return $this->prefix . ':' . $suffix;
    }

    protected function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return Cache::remember($this->key($key), $ttl ?? $this->ttl, $callback);
    }

    protected function forget(string $key): void
    {
        Cache::forget($this->key($key));
    }
}
