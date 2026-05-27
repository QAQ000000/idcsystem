<?php

namespace App\Modules\Admin\Services;

use App\Models\Plugin;
use App\Modules\Admin\Models\MarketplacePlugin;
use App\Plugins\Core\PluginManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PluginMarketplaceService
{
    private const VALID_TYPES = ['gateway', 'email', 'sms', 'server', 'oauth', 'captcha', 'certification'];

    public function browse(array $filters = []): LengthAwarePaginator
    {
        return MarketplacePlugin::query()
            ->when(! empty($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(! empty($filters['search']), function ($query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(($filters['verified'] ?? null) !== null && $filters['verified'] !== '', fn ($query) => $query->where('is_verified', (bool) $filters['verified']))
            ->when(($filters['sort'] ?? 'title') === 'popular', fn ($query) => $query->orderByDesc('downloads_count'))
            ->when(($filters['sort'] ?? 'title') === 'rating', fn ($query) => $query->orderByDesc('rating')->orderByDesc('reviews_count'))
            ->when(($filters['sort'] ?? 'title') === 'newest', fn ($query) => $query->latest())
            ->when(! in_array(($filters['sort'] ?? 'title'), ['popular', 'rating', 'newest'], true), fn ($query) => $query->orderBy('title'))
            ->paginate(12)
            ->withQueryString();
    }

    public function install(MarketplacePlugin $marketplacePlugin): Plugin
    {
        $this->ensureInstallable($marketplacePlugin);
        $this->ensurePluginFilesExist($marketplacePlugin);

        $installed = app(PluginManager::class)->install($marketplacePlugin->type, $marketplacePlugin->name);
        if (! $installed) {
            throw new RuntimeException('插件安装失败');
        }

        $marketplacePlugin->increment('downloads_count');

        return Plugin::query()
            ->where('name', $marketplacePlugin->name)
            ->where('type', $marketplacePlugin->type)
            ->firstOrFail();
    }

    public function uninstall(string $name): bool
    {
        return app(PluginManager::class)->uninstall($name);
    }

    public function ensureInstallable(MarketplacePlugin $plugin): void
    {
        if (! in_array($plugin->type, self::VALID_TYPES, true)) {
            throw new RuntimeException('插件类型不支持');
        }

        if ($plugin->installedPlugin()) {
            throw new RuntimeException('插件已安装');
        }

        $requirements = $plugin->requirements ?? [];
        if (! is_array($requirements) || $requirements === []) {
            return;
        }

        $this->checkPhpVersion($requirements['php'] ?? null);
        $this->checkLaravelVersion($requirements['laravel'] ?? null);
        $this->checkExtensions($requirements['extensions'] ?? []);
        $this->checkRequiredPlugins($requirements['plugins'] ?? []);
    }

    private function checkPhpVersion(mixed $version): void
    {
        if (is_string($version) && $version !== '' && version_compare(PHP_VERSION, $version, '<')) {
            throw new RuntimeException("需要 PHP {$version} 或更高版本");
        }
    }

    private function checkLaravelVersion(mixed $version): void
    {
        if (is_string($version) && $version !== '' && version_compare(app()->version(), $version, '<')) {
            throw new RuntimeException("需要 Laravel {$version} 或更高版本");
        }
    }

    private function checkExtensions(mixed $extensions): void
    {
        if (! is_array($extensions)) {
            return;
        }

        foreach ($extensions as $extension) {
            if (is_string($extension) && $extension !== '' && ! extension_loaded($extension)) {
                throw new RuntimeException("缺少 PHP 扩展：{$extension}");
            }
        }
    }

    private function checkRequiredPlugins(mixed $plugins): void
    {
        if (! is_array($plugins)) {
            return;
        }

        foreach ($plugins as $plugin) {
            if (! is_string($plugin) || $plugin === '') {
                continue;
            }

            $exists = Plugin::query()
                ->where('name', $plugin)
                ->where('status', 1)
                ->exists();

            if (! $exists) {
                throw new RuntimeException("缺少依赖插件：{$plugin}");
            }
        }
    }

    private function ensurePluginFilesExist(MarketplacePlugin $plugin): void
    {
        $typePath = $this->pluginTypePath($plugin->type);
        if (! File::exists($typePath)) {
            throw new RuntimeException('插件文件不存在，请先同步插件包');
        }

        $candidates = [
            $typePath.DIRECTORY_SEPARATOR.$this->studly($plugin->name).DIRECTORY_SEPARATOR.'plugin.json',
            $typePath.DIRECTORY_SEPARATOR.$plugin->name.DIRECTORY_SEPARATOR.'plugin.json',
        ];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return;
            }
        }

        foreach (File::directories($typePath) as $directory) {
            $manifest = $directory.DIRECTORY_SEPARATOR.'plugin.json';
            if (! File::exists($manifest)) {
                continue;
            }

            $payload = json_decode(File::get($manifest), true);
            if (is_array($payload) && ($payload['name'] ?? null) === $plugin->name && ($payload['type'] ?? null) === $plugin->type) {
                return;
            }
        }

        throw new RuntimeException('插件文件不存在，请先同步插件包');
    }

    private function pluginTypePath(string $type): string
    {
        $studlyPath = base_path('plugins/'.$this->studly($type));

        return File::exists($studlyPath) ? $studlyPath : base_path("plugins/{$type}");
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
