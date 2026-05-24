<?php

namespace App\Plugins\Contracts;

interface ServerModuleInterface extends PluginInterface
{
    public function createAccount(array $params): array;

    public function suspendAccount(array $params): bool;

    public function unsuspendAccount(array $params): bool;

    public function terminateAccount(array $params): bool;

    public function changePassword(array $params): bool;

    public function getUsageStats(array $params): array;
}