<?php

namespace App\Models;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        app(SettingsService::class)->set($key, $value, $group);
    }
}
