<?php

namespace App\Plugins\Contracts;

interface CaptchaInterface extends PluginInterface
{
    public function generate(): array;

    public function verify(string $code, string $key): bool;
}