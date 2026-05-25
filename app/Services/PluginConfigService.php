<?php

namespace App\Services;

use App\Models\Plugin;
use App\Plugins\Core\PluginManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PluginConfigService
{
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|credential|authorization|cookie|session_id|session|bearer|access_key|private_key|key|signature|sign)$/i';

    public function get(string $name): array
    {
        return $this->plugin($name)->config ?? [];
    }

    public function save(string $name, array $config): Plugin
    {
        $plugin = $this->plugin($name);
        $manager = app(PluginManager::class);
        $fields = $this->configFieldsByKey($plugin, $manager);
        $plugin->update(['config' => $this->mergeConfig(
            $plugin->config ?? [],
            $this->filterAllowedConfig($plugin, $config, $manager),
            $fields->all()
        )]);
        $manager->forget($plugin->name, $plugin->type);

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

    private function mergeConfig(array $current, array $incoming, array $fields = []): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value)) {
                $current[$key] = $this->mergeConfig(
                    is_array($current[$key] ?? null) ? $current[$key] : [],
                    $value,
                    $fields
                );

                continue;
            }

            if ($this->isSensitiveField((string) $key, $fields[$key] ?? []) && $this->isBlank($value)) {
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }

    private function filterAllowedConfig(Plugin $plugin, array $config, PluginManager $manager): array
    {
        $fields = $this->configFieldsByKey($plugin, $manager);

        if ($fields->isEmpty()) {
            return [];
        }

        $filtered = array_intersect_key($config, array_flip($fields->keys()->all()));

        foreach ($filtered as $key => $value) {
            $type = $fields[$key]['type'] ?? 'text';

            if ($type === 'boolean') {
                $filtered[$key] = in_array($value, [true, 1, '1', 'true', 'on'], true);

                continue;
            }

            if (is_array($value) || is_object($value)) {
                unset($filtered[$key]);

                continue;
            }

            if ($type === 'number') {
                if (is_numeric($value)) {
                    $number = $value + 0;
                    if (!$this->numberWithinRange($number, $fields[$key])) {
                        unset($filtered[$key]);

                        continue;
                    }

                    $filtered[$key] = $number;
                } else {
                    unset($filtered[$key]);
                }

                continue;
            }

            $filtered[$key] = (string) $value;
        }

        return $filtered;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1;
    }

    private function numberWithinRange(int|float $value, array $field): bool
    {
        if (isset($field['min']) && is_numeric($field['min']) && $value < $field['min'] + 0) {
            return false;
        }

        if (isset($field['max']) && is_numeric($field['max']) && $value > $field['max'] + 0) {
            return false;
        }

        return true;
    }

    private function isSensitiveField(string $key, array $field = []): bool
    {
        return ($field['type'] ?? null) === 'password' || $this->isSensitiveKey($key);
    }

    private function configFieldsByKey(Plugin $plugin, PluginManager $manager): \Illuminate\Support\Collection
    {
        return collect($manager->configFields($plugin))
            ->filter(fn ($field) => is_array($field) && !empty($field['key']))
            ->keyBy(fn ($field) => (string) $field['key']);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
