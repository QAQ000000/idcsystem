<?php

namespace App\Providers;

use App\Plugins\Core\PluginManager;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginManager::class, function () {
            return new PluginManager();
        });

        $this->app->alias(PluginManager::class, 'plugin.manager');
    }

    public function boot(): void
    {
        $this->loadPluginRoutes();
    }

    protected function loadPluginRoutes(): void
    {
        if (!$this->app->routesAreCached()) {
            $pluginsPath = base_path('plugins');

            if (!is_dir($pluginsPath)) {
                return;
            }

            foreach (glob($pluginsPath . '/*/*/routes/web.php') as $routeFile) {
                $this->loadRoutesFrom($routeFile);
            }
        }
    }
}