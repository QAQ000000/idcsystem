<?php

namespace App\Services;

use App\Models\Plugin;
use App\Plugins\Core\PluginManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PluginConfigService
{
    private const SENSITIVE_KEY_PATTERN = '/(secret|password|passwd|token|key)$/i';

    public function get(string $name): array
    {
        return $this->plugin($name)->config ?? [];
    }

    public function save(string $name, array $config): Plugin
    {
        $plugin = $this->plugin($name);
        $plugin->update(['config' => $this->mergeConfig($plugin->config ?? [], $config)]);
        app(PluginManager::class)->forget($plugin->name, $plugin->type);

        return $plugin->fresh();
    }

    public function plugin(string $name): Plugin
    {
        $plugin = Plugin::query()->where('name', $name)->first();

        if (!$plugin) {
            throw (new ModelNotFoundException())->setModel(Plugin::class, [$name]);
        }

        return $plugin;
    }

    private function mergeConfig(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value)) {
                $current[$key] = $this->mergeConfig(
                    is_array($current[$key] ?? null) ? $current[$key] : [],
                    $value
                );

                continue;
            }

            if ($this->isSensitiveKey((string) $key) && $this->isBlank($value)) {
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
