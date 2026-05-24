<?php

namespace App\Plugins\Contracts;

interface PaymentGatewayInterface extends PluginInterface
{
    public function getType(): string;

    public function pay(array $order): array;

    public function notify(array $data): bool;

    public function refund(string $transId, float $amount): bool;

    public function query(string $transId): array;
}