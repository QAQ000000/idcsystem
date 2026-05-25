<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ThemeService
{
    public function __construct(private ?SettingsService $settings = null)
    {
        $this->settings ??= app(SettingsService::class);
    }

    public function active(): string
    {
        if (!Schema::hasTable('settings')) {
            return 'default';
        }

        try {
            $theme = trim((string) $this->settings->get('theme', 'default'));
        } catch (Throwable) {
            return 'default';
        }

        return $this->exists($theme) ? $theme : 'default';
    }

    public function available(): array
    {
        $base = resource_path('themes');
        if (!File::isDirectory($base)) {
            return ['default'];
        }

        $themes = collect(File::directories($base))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1)
            ->sort()
            ->values()
            ->all();

        return $themes === [] ? ['default'] : $themes;
    }

    public function view(string $view): string
    {
        $active = $this->active();
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $view) . '.blade.php';

        if (File::exists(resource_path("themes/{$active}/{$relative}"))) {
            return 'theme::' . $view;
        }

        return 'themes.default.' . $view;
    }

    private function exists(string $theme): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $theme) === 1
            && File::isDirectory(resource_path("themes/{$theme}"));
    }
}
