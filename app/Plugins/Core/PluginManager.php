<?php

namespace App\Plugins\Core;

use App\Models\Plugin;
use App\Models\PaymentAttempt;
use App\Models\Setting;
use App\Models\EmailLog;
use App\Models\SmsLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class PluginManager
{
    private const VALID_TYPES = ['gateway', 'email', 'sms', 'server', 'oauth', 'captcha', 'certification'];
    private const SAFE_NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

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
        if (!$this->isValidType($plugin->type) || !$this->isSafeName($plugin->name)) {
            return null;
        }

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
        if (!$this->isValidType($plugin->type) || !$this->isSafeName($plugin->name)) {
            return null;
        }

        $routeFile = $this->pluginPath($plugin->type, $plugin->name) . '/routes/web.php';

        return File::exists($routeFile) ? $routeFile : null;
    }

    public function scan(string $type): array
    {
        if (!$this->isValidType($type)) {
            return [];
        }

        $pluginsPath = $this->typePath($type);

        if (!File::exists($pluginsPath)) {
            return [];
        }

        $directories = File::directories($pluginsPath);
        $plugins = [];

        foreach ($directories as $dir) {
            $pluginJsonPath = $dir . '/plugin.json';

            if (File::exists($pluginJsonPath)) {
                $pluginJson = $this->readManifest($pluginJsonPath);
                $pluginName = $pluginJson['name'] ?? basename($dir);
                $pluginType = $pluginJson['type'] ?? $type;
                if (!$this->manifestMatchesRequestedPlugin($type, $pluginName, $pluginType)) {
                    continue;
                }

                $pluginJson['installed'] = Plugin::query()
                    ->where('name', $pluginName)
                    ->where('type', $pluginType)
                    ->exists();
                $plugins[] = $pluginJson;
            }
        }

        return $plugins;
    }

    public function manifest(string $type, string $name): array
    {
        if (!$this->isValidType($type) || !$this->isSafeName($name)) {
            return [];
        }

        $pluginJsonPath = $this->pluginPath($type, $name) . '/plugin.json';

        if (!File::exists($pluginJsonPath)) {
            return [];
        }

        return $this->readManifest($pluginJsonPath);
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
        if (!$this->isValidType($type) || !$this->isSafeName($name)) {
            return false;
        }

        $pluginPath = $this->pluginPath($type, $name);
        $pluginJsonPath = $pluginPath . '/plugin.json';

        if (!File::exists($pluginJsonPath)) {
            return false;
        }

        $pluginJson = $this->readManifest($pluginJsonPath);
        $pluginName = $pluginJson['name'] ?? $name;
        $pluginType = $pluginJson['type'] ?? $type;
        if (!$this->manifestMatchesRequestedPlugin($type, $pluginName, $pluginType)) {
            return false;
        }

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

    private function readManifest(string $path): array
    {
        $manifest = json_decode(File::get($path), true);

        return is_array($manifest) ? $manifest : [];
    }

    private function manifestMatchesRequestedPlugin(string $requestedType, mixed $pluginName, mixed $pluginType): bool
    {
        return is_string($pluginName)
            && is_string($pluginType)
            && $pluginType === $requestedType
            && $this->isValidType($pluginType)
            && $this->isSafeName($pluginName);
    }

    private function isValidType(string $type): bool
    {
        return in_array($type, self::VALID_TYPES, true);
    }

    private function isSafeName(string $name): bool
    {
        return preg_match(self::SAFE_NAME_PATTERN, $name) === 1;
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

        foreach (File::directories($typePath) as $directory) {
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'plugin.json';
            if (File::exists($manifestPath) && ($this->readManifest($manifestPath)['name'] ?? null) === $name) {
                return $directory;
            }
        }

        return $typePath . DIRECTORY_SEPARATOR . $name;
    }

    private function hasBusinessReferences(Plugin $plugin): bool
    {
        return match ($plugin->type) {
            'server' => $this->tableHasBusinessReference('products', fn () => \App\Modules\Product\Models\Product::query()
                ->where('server_type', $plugin->name)
                ->exists()),
            'gateway' => $this->tableHasBusinessReference('accounts', fn () => \App\Modules\Finance\Models\Account::query()
                ->where('payment_method', $plugin->name)
                ->exists())
                || $this->tableHasBusinessReference('invoices', fn () => \App\Modules\Finance\Models\Invoice::query()
                    ->where('payment_method', $plugin->name)
                    ->whereIn('status', ['Unpaid', 'Paid', 'Partially Refunded'])
                    ->exists())
                || $this->tableHasBusinessReference('payment_attempts', fn () => PaymentAttempt::query()
                    ->where('gateway', $plugin->name)
                    ->where('status', 'pending')
                    ->exists()),
            'email' => $this->tableHasBusinessReference('settings', fn () => Setting::query()
                ->where('key', 'default_email_provider')
                ->where('value', $plugin->name)
                ->exists())
                || $this->tableHasBusinessReference('email_logs', fn () => EmailLog::query()
                    ->where('provider', $plugin->name)
                    ->whereIn('status', ['pending', 'processing', 'failed'])
                    ->exists()),
            'sms' => $this->tableHasBusinessReference('settings', fn () => Setting::query()
                ->where('key', 'default_sms_provider')
                ->where('value', $plugin->name)
                ->exists())
                || $this->tableHasBusinessReference('sms_logs', fn () => SmsLog::query()
                    ->where('provider', $plugin->name)
                    ->whereIn('status', ['pending', 'processing', 'failed'])
                    ->exists()),
            default => false,
        };
    }

    private function tableHasBusinessReference(string $table, callable $callback): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        return (bool) $callback();
    }
}
