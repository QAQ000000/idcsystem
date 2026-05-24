<?php

namespace App\Services;

use App\Models\Plugin;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PluginConfigService
{
    public function get(string $name): array
    {
        return $this->plugin($name)->config ?? [];
    }

    public function save(string $name, array $config): Plugin
    {
        $plugin = $this->plugin($name);
        $plugin->update(['config' => $config]);

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
}
