<?php

namespace App\Plugins\Contracts;

interface PluginInterface
{
    public function getName(): string;
    public function getTitle(): string;
    public function getVersion(): string;
    public function getType(): string;
    public function install(): bool;
    public function uninstall(): bool;
    public function getConfig(): array;
    public function setConfig(array $config): void;
}