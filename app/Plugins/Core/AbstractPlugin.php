<?php

namespace App\Plugins\Core;

use App\Plugins\Contracts\PluginInterface;
use App\Models\Plugin;

abstract class AbstractPlugin implements PluginInterface
{
    protected array $config = [];
    protected ?Plugin $model = null;

    abstract public function getName(): string;
    abstract public function getTitle(): string;
    abstract public function getVersion(): string;
    abstract public function getType(): string;

    public function install(): bool
    {
        Plugin::updateOrCreate(
            ['name' => $this->getName()],
            [
                'title'       => $this->getTitle(),
                'type'        => $this->getType(),
                'version'     => $this->getVersion(),
                'status'      => 0,
                'description' => $this->getDescription(),
            ]
        );
        return true;
    }

    public function uninstall(): bool
    {
        Plugin::where('name', $this->getName())->delete();
        return true;
    }

    public function getConfig(): array
    {
        $this->model = Plugin::where('name', $this->getName())->first();

        return $this->model ? ($this->model->config ?? []) : [];
    }

    public function setConfig(array $config): void
    {
        Plugin::where('name', $this->getName())->update(['config' => $config]);
        $this->config = $config;
    }

    public function getDescription(): string
    {
        return '';
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        $config = $this->getConfig();
        return $config[$key] ?? $default;
    }
}
