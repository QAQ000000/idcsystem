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
        if ($instance) {
            $this->plugins[$name] = $instance;
        }

        return $instance;
    }

    public function loadPlugin(Plugin $plugin): ?object
    {
        $pluginPath = $this->pluginPath($plugin->type, $plugin->name);

        if (!File::exists($pluginPath)) {
            return null;
        }

        $pluginJsonPath = $pluginPath . '/plugin.json';
        if (!File::exists($pluginJsonPath)) {
            return null;
        }

        $pluginJson = json_decode(File::get($pluginJsonPath), true);
        $fullClass = $this->pluginClass($plugin->type, $plugin->name, $pluginJson);

        if (!$fullClass || !class_exists($fullClass)) {
            return null;
        }

        return new $fullClass();
    }

    public function scan(string $type): array
    {
        $pluginsPath = $this->typePath($type);

        if (!File::exists($pluginsPath)) {
            return [];
        }

        $directories = File::directories($pluginsPath);
        $plugins = [];

        foreach ($directories as $dir) {
            $pluginJsonPath = $dir . '/plugin.json';

            if (File::exists($pluginJsonPath)) {
                $pluginJson = json_decode(File::get($pluginJsonPath), true) ?: [];
                $pluginJson['installed'] = Plugin::query()->where('name', $pluginJson['name'] ?? basename($dir))->exists();
                $plugins[] = $pluginJson;
            }
        }

        return $plugins;
    }

    public function install(string $type, string $name): bool
    {
        $pluginPath = $this->pluginPath($type, $name);
        $pluginJsonPath = $pluginPath . '/plugin.json';

        if (!File::exists($pluginJsonPath)) {
            return false;
        }

        $pluginJson = json_decode(File::get($pluginJsonPath), true) ?: [];
        $fullClass = $this->pluginClass($type, $name, $pluginJson);

        if (!$fullClass || !class_exists($fullClass)) {
            return false;
        }

        $instance = new $fullClass();
        if (method_exists($instance, 'install')) {
            $instance->install();
        }

        Plugin::query()->updateOrCreate(
            ['name' => $pluginJson['name'] ?? $name],
            [
                'title' => $pluginJson['title'] ?? $name,
                'type' => $pluginJson['type'] ?? $type,
                'version' => $pluginJson['version'] ?? '1.0.0',
                'author' => $pluginJson['author'] ?? null,
                'description' => $pluginJson['description'] ?? '',
                'status' => 0,
                'config' => Plugin::query()->where('name', $pluginJson['name'] ?? $name)->value('config') ?? [],
            ]
        );

        return true;
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

    private function pluginClass(string $type, string $name, array $pluginJson): ?string
    {
        $entryClass = $pluginJson['entry'] ?? null;
        if (!$entryClass) {
            return null;
        }

        if (str_contains($entryClass, '\\')) {
            return $entryClass;
        }

        return 'Plugins\\'
            . $this->studly($type)
            . '\\'
            . $this->studly($name)
            . '\\src\\'
            . $entryClass;
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function typePath(string $type): string
    {
        $studlyPath = base_path('plugins/' . $this->studly($type));
        if (File::exists($studlyPath)) {
            return $studlyPath;
        }

        return base_path("plugins/{$type}");
    }

    private function pluginPath(string $type, string $name): string
    {
        $typePath = $this->typePath($type);
        $studlyPath = $typePath . DIRECTORY_SEPARATOR . $this->studly($name);
        if (File::exists($studlyPath)) {
            return $studlyPath;
        }

        return $typePath . DIRECTORY_SEPARATOR . $name;
    }
}
