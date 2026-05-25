<?php

namespace App\Plugins\Core;

use App\Models\Plugin;
use App\Models\PaymentAttempt;
use App\Models\Setting;
use App\Models\EmailLog;
use App\Models\SmsLog;
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
        $plugin = Plugin::query()
            ->when($this->currentType, fn ($query, string $type) => $query->where('type', $type))
            ->where('name', $name)
            ->where('status', 1)
            ->first();
        if (!$plugin) {
            return null;
        }

        $cacheKey = $plugin->type . ':' . $plugin->name;
        if (isset($this->plugins[$cacheKey])) {
            return $this->plugins[$cacheKey];
        }

        $instance = $this->loadPlugin($plugin);
        if ($instance) {
            $this->plugins[$cacheKey] = $instance;
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

    public function routeFile(Plugin $plugin): ?string
    {
        $routeFile = $this->pluginPath($plugin->type, $plugin->name) . '/routes/web.php';

        return File::exists($routeFile) ? $routeFile : null;
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

    public function manifest(string $type, string $name): array
    {
        $pluginJsonPath = $this->pluginPath($type, $name) . '/plugin.json';

        if (!File::exists($pluginJsonPath)) {
            return [];
        }

        return json_decode(File::get($pluginJsonPath), true) ?: [];
    }

    public function configFields(Plugin $plugin): array
    {
        $fields = $this->manifest($plugin->type, $plugin->name)['config_schema'] ?? [];

        if (!is_array($fields) || $fields === []) {
            return [
                ['key' => 'app_id', 'label' => '应用 ID', 'type' => 'text'],
                ['key' => 'app_secret', 'label' => '应用密钥', 'type' => 'password'],
                ['key' => 'endpoint', 'label' => '接口地址', 'type' => 'text'],
                ['key' => 'callback_url', 'label' => '回调地址', 'type' => 'text'],
                ['key' => 'notes', 'label' => '备注', 'type' => 'textarea'],
            ];
        }

        return collect($fields)
            ->filter(fn ($field) => is_array($field) && !empty($field['key']))
            ->map(function ($field) {
                $normalized = [
                    'key' => (string) $field['key'],
                    'label' => (string) ($field['label'] ?? $field['key']),
                    'type' => in_array(($field['type'] ?? 'text'), ['text', 'password', 'textarea', 'number', 'boolean'], true)
                        ? (string) ($field['type'] ?? 'text')
                        : 'text',
                    'placeholder' => (string) ($field['placeholder'] ?? ''),
                ];

                foreach (['min', 'max', 'options'] as $key) {
                    if (array_key_exists($key, $field)) {
                        $normalized[$key] = $field[$key];
                    }
                }

                return $normalized;
            })
            ->values()
            ->all();
    }

    public function install(string $type, string $name): bool
    {
        $pluginPath = $this->pluginPath($type, $name);
        $pluginJsonPath = $pluginPath . '/plugin.json';

        if (!File::exists($pluginJsonPath)) {
            return false;
        }

        $pluginJson = json_decode(File::get($pluginJsonPath), true) ?: [];
        $pluginName = $pluginJson['name'] ?? $name;
        $pluginType = $pluginJson['type'] ?? $type;
        $existingDifferentType = Plugin::query()
            ->where('name', $pluginName)
            ->where('type', '!=', $pluginType)
            ->exists();
        if ($existingDifferentType) {
            return false;
        }

        $fullClass = $this->pluginClass($type, $name, $pluginJson);

        if (!$fullClass || !class_exists($fullClass)) {
            return false;
        }

        $existing = Plugin::query()->where('name', $pluginName)->first();

        $instance = new $fullClass();
        if (!$existing && method_exists($instance, 'install')) {
            $instance->install();
        }

        Plugin::query()->updateOrCreate(
            ['name' => $pluginName],
            [
                'title' => $pluginJson['title'] ?? $name,
                'type' => $pluginType,
                'version' => $pluginJson['version'] ?? '1.0.0',
                'author' => $pluginJson['author'] ?? null,
                'description' => $pluginJson['description'] ?? '',
                'status' => $existing?->status ?? 0,
                'config' => $existing?->config ?? [],
            ]
        );
        $this->forget($pluginName, $pluginType);

        return true;
    }

    public function uninstall(string $name): bool
    {
        $plugin = Plugin::where('name', $name)->first();

        if (!$plugin) {
            return false;
        }

        if ($this->hasBusinessReferences($plugin)) {
            return false;
        }

        $instance = $this->loadPlugin($plugin);

        if ($instance) {
            $uninstalled = $instance->uninstall();
            if ($uninstalled) {
                $this->forget($plugin->name, $plugin->type);
            }

            return $uninstalled;
        }

        return false;
    }

    public function enable(string $name): bool
    {
        $plugin = Plugin::query()->where('name', $name)->first();
        if (!$plugin) {
            return false;
        }

        if (!$this->loadPlugin($plugin)) {
            return false;
        }

        $enabled = $plugin->update(['status' => 1]);
        $this->forget($plugin->name, $plugin->type);

        return $enabled;
    }

    public function disable(string $name): bool
    {
        $plugin = Plugin::query()->where('name', $name)->first();
        if (!$plugin) {
            return false;
        }

        if ($this->hasBusinessReferences($plugin)) {
            return false;
        }

        $disabled = $plugin->update(['status' => 0]);
        $this->forget($plugin->name, $plugin->type);

        return $disabled;
    }

    public function forget(string $name, ?string $type = null): void
    {
        if ($type !== null) {
            unset($this->plugins[$type . ':' . $name]);

            return;
        }

        foreach (array_keys($this->plugins) as $key) {
            if (str_ends_with($key, ':' . $name)) {
                unset($this->plugins[$key]);
            }
        }
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

    private function hasBusinessReferences(Plugin $plugin): bool
    {
        return match ($plugin->type) {
            'server' => \App\Modules\Product\Models\Product::query()
                ->where('server_type', $plugin->name)
                ->exists(),
            'gateway' => \App\Modules\Finance\Models\Account::query()
                ->where('payment_method', $plugin->name)
                ->exists()
                || \App\Modules\Finance\Models\Invoice::query()
                    ->where('payment_method', $plugin->name)
                    ->whereIn('status', ['Unpaid', 'Paid', 'Partially Refunded'])
                    ->exists()
                || PaymentAttempt::query()
                    ->where('gateway', $plugin->name)
                    ->where('status', 'pending')
                    ->exists(),
            'email' => Setting::query()
                ->where('key', 'default_email_provider')
                ->where('value', $plugin->name)
                ->exists()
                || EmailLog::query()
                    ->where('provider', $plugin->name)
                    ->whereIn('status', ['pending', 'processing', 'failed'])
                    ->exists(),
            'sms' => Setting::query()
                ->where('key', 'default_sms_provider')
                ->where('value', $plugin->name)
                ->exists()
                || SmsLog::query()
                    ->where('provider', $plugin->name)
                    ->whereIn('status', ['pending', 'processing', 'failed'])
                    ->exists(),
            default => false,
        };
    }
}
