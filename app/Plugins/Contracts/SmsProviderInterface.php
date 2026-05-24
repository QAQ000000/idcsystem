<?php

namespace App\Plugins\Contracts;

interface SmsProviderInterface extends PluginInterface
{
    public function send(string $phone, string $templateCode, array $params = []): bool;
}