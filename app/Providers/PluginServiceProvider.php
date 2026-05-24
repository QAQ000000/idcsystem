<?php

namespace App\Providers;

use App\Models\Plugin;
use App\Plugins\Core\PluginManager;
use Illuminate\Database\QueryException;
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
        if ($this->app->routesAreCached()) {
            return;
        }

        if (!is_dir(base_path('plugins'))) {
            return;
        }

        try {
            $manager = $this->app->make(PluginManager::class);

            foreach (Plugin::query()->where('status', 1)->get() as $plugin) {
                $routeFile = $manager->routeFile($plugin);
                if ($routeFile) {
                    $this->loadRoutesFrom($routeFile);
                }
            }
        } catch (QueryException) {
            return;
        }
    }
}
