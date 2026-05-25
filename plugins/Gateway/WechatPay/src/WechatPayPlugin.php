<?php

namespace Plugins\Gateway\WechatPay\src;

use App\Plugins\Contracts\PaymentGatewayInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Facades\Route;
use Throwable;

class WechatPayPlugin extends AbstractPlugin implements PaymentGatewayInterface
{
    private const NAME = 'wechat_pay';
    private const TITLE = '微信支付官方';

    public function getName(): string { return self::NAME; }
    public function getTitle(): string { return self::TITLE; }
    public function getVersion(): string { return '1.0.0'; }
    public function getType(): string { return 'gateway'; }
    public function getDescription(): string { return '通过微信支付 API v3 H5 收款'; }

    public function pay(array $order): array
    {
        try {
            $result = $this->client($this->validatedConfig())->buildH5Payment([
                'out_trade_no' => (string) ($order['invoice_id'] ?? ''),
                'notify_url' => $this->notifyUrl(),
                'description' => '账单 #' . ($order['invoice_number'] ?? $order['invoice_id'] ?? ''),
                'amount' => (float) ($order['amount'] ?? 0),
                'client_ip' => request()->ip() ?: '127.0.0.1',
            ]);
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage() ?: self::TITLE . '未配置'];
        }

        return [
            'success' => true,
            'gateway' => self::NAME,
            'invoice_id' => $order['invoice_id'] ?? null,
            'amount' => (float) ($order['amount'] ?? 0),
            'status' => 'pending',
            'payment_url' => $result['mweb_url'],
            'pay_type' => 'redirect',
        ];
    }

    public function notify(array $data): bool
    {
        try {
            return $this->client($this->validatedConfig())->verifyNotify($this->wechatPayload($data));
        } catch (Throwable) {
            return false;
        }
    }

    public function refund(string $transId, float $amount): bool
    {
        try {
            $result = $this->client($this->validatedConfig())->refund($transId, $amount);
        } catch (Throwable) {
            return false;
        }

        return (bool) ($result['success'] ?? false);
    }

    public function query(string $transId): array
    {
        try {
            return $this->client($this->validatedConfig())->query($transId);
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    public function normalizedCallback(array $data): array
    {
        $resource = $this->client($this->validatedConfig())->decryptResource($data);
        $transaction = is_array($resource['resource'] ?? null) ? $resource['resource'] : $resource;

        return [
            'invoice_id' => $transaction['out_trade_no'] ?? null,
            'trans_id' => $transaction['transaction_id'] ?? null,
            'amount' => isset($transaction['amount']['total']) ? round(((float) $transaction['amount']['total']) / 100, 2) : null,
            'status' => strtolower((string) ($transaction['trade_state'] ?? '')),
        ] + $data;
    }

    private function client(array $config): WechatPayClient
    {
        return new WechatPayClient($config);
    }

    private function notifyUrl(): string
    {
        return Route::has('plugin.wechat_pay.notify')
            ? route('plugin.wechat_pay.notify')
            : url('/plugin/wechat_pay/notify');
    }

    private function validatedConfig(): array
    {
        $config = $this->getConfig();
        foreach (['mch_id', 'api_v3_key', 'cert_serial', 'private_key', 'app_id'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new \InvalidArgumentException(self::TITLE . '未配置');
            }
        }

        return $config;
    }

    private function wechatPayload(array $data): array
    {
        unset($data['invoice_id'], $data['trans_id'], $data['amount'], $data['status']);

        return $data;
    }
}
