<?php

namespace App\Plugins\Contracts;

interface EmailProviderInterface extends PluginInterface
{
    public function send(string $to, string $subject, string $body, array $options = []): bool;
}