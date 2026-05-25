<?php

namespace Plugins\Gateway\AlipaySdk\src;

use App\Plugins\Contracts\PaymentGatewayInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Facades\Route;
use Throwable;

class AlipaySdkPlugin extends AbstractPlugin implements PaymentGatewayInterface
{
    private const NAME = 'alipay_sdk';
    private const TITLE = '支付宝官方';

    public function getName(): string { return self::NAME; }
    public function getTitle(): string { return self::TITLE; }
    public function getVersion(): string { return '1.0.0'; }
    public function getType(): string { return 'gateway'; }
    public function getDescription(): string { return '通过支付宝官方接口收取网页付款'; }

    public function pay(array $order): array
    {
        try {
            $config = $this->validatedConfig();
            $url = $this->client($config)->buildRedirectUrl([
                'out_trade_no' => (string) ($order['invoice_id'] ?? ''),
                'notify_url' => $this->notifyUrl(),
                'return_url' => $this->returnUrl($config, $order),
                'subject' => '账单 #' . ($order['invoice_number'] ?? $order['invoice_id'] ?? ''),
                'total_amount' => (float) ($order['amount'] ?? 0),
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
            'payment_url' => $url,
            'pay_type' => 'redirect',
        ];
    }

    public function notify(array $data): bool
    {
        try {
            return $this->client($this->validatedConfig())->verifyNotify($this->alipayPayload($data));
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

    private function client(array $config): AlipayClient
    {
        return new AlipayClient($config);
    }

    private function notifyUrl(): string
    {
        return Route::has('plugin.alipay_sdk.notify')
            ? route('plugin.alipay_sdk.notify')
            : url('/plugin/alipay_sdk/notify');
    }

    private function returnUrl(array $config, array $order): string
    {
        $configured = trim((string) ($config['return_url'] ?? ''));

        return $configured !== ''
            ? $configured
            : (string) ($order['return_url'] ?? route('client.invoices.show', $order['invoice_id']));
    }

    private function validatedConfig(): array
    {
        $config = $this->getConfig();
        foreach (['app_id', 'private_key', 'alipay_public_key'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new \InvalidArgumentException(self::TITLE . '未配置');
            }
        }

        return $config;
    }

    private function alipayPayload(array $data): array
    {
        unset($data['invoice_id'], $data['trans_id'], $data['amount'], $data['status']);

        return $data;
    }
}
