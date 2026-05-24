<?php

namespace App\Plugins\Core;

use App\Models\Plugin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class PluginManager
{
    protected array $plugins = [];
    protected ?string $currentType = null;

    public function type(string $type): self
    {
        $this->currentType = $type;
        return $this;
    }

    public function all(): Collection
    {
        return Plugin::when($this->currentType, function ($query, $type) {
            return $query->where('type', $type);
        })->get();
    }

    public function enabled(): Collection
    {
        return Plugin::where('status', 1)
            ->when($this->currentType, function ($query, $type) {
                return $query->where('type', $type);
            })->get();
    }

    public function get(string $name): ?object
    {
        if (isset($this->plugins[$name])) {
            return $this->plugins[$name];
        }

        $plugin = Plugin::where('name', $name)->first();
        if (!$plugin) {
            return null;
        }

        $instance = $this->loadPlugin($plugin);
        $this->plugins[$name] = $instance;

        return $instance;
    }

    public function loadPlugin(Plugin $plugin): ?object
    {
        $pluginPath = base_path("plugins/{$plugin->type}/{$plugin->name}");

        if (!File::exists($pluginPath)) {
            return null;
        }

        $pluginJsonPath = $pluginPath . '/plugin.json';
        if (!File::exists($pluginJsonPath)) {
            return null;
        }

        $pluginJson = json_decode(File::get($pluginJsonPath), true);
        $entryClass = $pluginJson['entry'] ?? null;

        if (!$entryClass) {
            return null;
        }

        $fullClass = "Plugins\\" . ucfirst($plugin->type) . "\\" . ucfirst($plugin->name) . "\\src\\" . $entryClass;

        if (!class_exists($fullClass)) {
            return null;
        }

        return new $fullClass();
    }

    public function scan(string $type): array
    {
        $pluginsPath = base_path("plugins/{$type}");

        if (!File::exists($pluginsPath)) {
            return [];
        }

        $directories = File::directories($pluginsPath);
        $plugins = [];

        foreach ($directories as $dir) {
            $pluginJsonPath = $dir . '/plugin.json';

            if (File::exists($pluginJsonPath)) {
                $pluginJson = json_decode(File::get($pluginJsonPath), true);
                $plugins[] = $pluginJson;
            }
        }

        return $plugins;
    }

    public function install(string $type, string $name): bool
    {
        $pluginPath = base_path("plugins/{$type}/{$name}");
        $pluginJsonPath = $pluginPath . '/plugin.json';

        if (!File::exists($pluginJsonPath)) {
            return false;
        }

        $pluginJson = json_decode(File::get($pluginJsonPath), true);
        $entryClass = $pluginJson['entry'] ?? null;

        if (!$entryClass) {
            return false;
        }

        $fullClass = "Plugins\\" . ucfirst($type) . "\\" . ucfirst($name) . "\\src\\" . $entryClass;

        if (!class_exists($fullClass)) {
            return false;
        }

        $instance = new $fullClass();
        return $instance->install();
    }

    public function uninstall(string $name): bool
    {
        $plugin = Plugin::where('name', $name)->first();

        if (!$plugin) {
            return false;
        }

        $instance = $this->loadPlugin($plugin);

        if ($instance) {
            return $instance->uninstall();
        }

        return false;
    }

    public function enable(string $name): bool
    {
        return Plugin::where('name', $name)->update(['status' => 1]) > 0;
    }

    public function disable(string $name): bool
    {
        return Plugin::where('name', $name)->update(['status' => 0]) > 0;
    }
}