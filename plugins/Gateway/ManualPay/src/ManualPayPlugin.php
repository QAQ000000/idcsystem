<?php

namespace Plugins\Gateway\ManualPay\src;

use App\Plugins\Contracts\PaymentGatewayInterface;
use App\Plugins\Core\AbstractPlugin;

class ManualPayPlugin extends AbstractPlugin implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'manual_pay';
    }

    public function getTitle(): string
    {
        return '线下转账';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getType(): string
    {
        return 'gateway';
    }

    public function getDescription(): string
    {
        return '内置线下转账支付插件，用于人工确认收款。';
    }

    public function pay(array $order): array
    {
        $config = $this->getConfig();

        return [
            'success' => true,
            'gateway' => $this->getName(),
            'invoice_id' => $order['invoice_id'] ?? null,
            'amount' => (float) ($order['amount'] ?? 0),
            'status' => 'pending',
            'payment_url' => null,
            'message' => $config['instructions'] ?? '请线下转账后联系管理员确认收款。',
            'bank_name' => $config['bank_name'] ?? '',
            'account_name' => $config['account_name'] ?? '',
            'account_number' => $config['account_number'] ?? '',
        ];
    }

    public function notify(array $data): bool
    {
        return ($data['status'] ?? null) === 'paid'
            && isset($data['invoice_id'], $data['amount'], $data['trans_id']);
    }

    public function refund(string $transId, float $amount): bool
    {
        return $transId !== '' && $amount > 0;
    }

    public function query(string $transId): array
    {
        return [
            'success' => $transId !== '',
            'trans_id' => $transId,
            'status' => $transId !== '' ? 'manual_review' : 'missing',
        ];
    }
}
