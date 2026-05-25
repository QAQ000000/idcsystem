<?php

namespace Plugins\Gateway\EpayWxpay\src;

use App\Plugins\Contracts\PaymentGatewayInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Facades\Route;
use Throwable;

class EpayWxpayPlugin extends AbstractPlugin implements PaymentGatewayInterface
{
    private const PAY_TYPE = 'wxpay';
    private const NAME = 'epay_wxpay';
    private const TITLE = '易支付 - 微信支付';
    private const DESCRIPTION = '通过彩虹易支付聚合平台收取微信付款';

    public function getName(): string { return self::NAME; }
    public function getTitle(): string { return self::TITLE; }
    public function getVersion(): string { return '1.0.0'; }
    public function getType(): string { return 'gateway'; }
    public function getDescription(): string { return self::DESCRIPTION; }

    public function pay(array $order): array
    {
        try {
            $config = $this->validatedConfig();
            $url = $this->client($config)->buildRedirectUrl([
                'type' => self::PAY_TYPE,
                'out_trade_no' => (string) ($order['invoice_id'] ?? ''),
                'notify_url' => $this->notifyUrl(),
                'return_url' => $this->returnUrl($config, $order),
                'name' => '账单 #' . ($order['invoice_number'] ?? $order['invoice_id'] ?? ''),
                'money' => number_format((float) ($order['amount'] ?? 0), 2, '.', ''),
            ]);
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage() ?: self::TITLE . '未配置'];
        }

        return ['success' => true, 'gateway' => self::NAME, 'invoice_id' => $order['invoice_id'] ?? null, 'amount' => (float) ($order['amount'] ?? 0), 'status' => 'pending', 'payment_url' => $url, 'pay_type' => 'redirect'];
    }

    public function notify(array $data): bool
    {
        try {
            return $this->client($this->validatedConfig())->verifyNotify($this->epayPayload($data));
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

        return in_array((string) ($result['code'] ?? $result['status'] ?? ''), ['1', 'success', 'true'], true);
    }

    public function query(string $transId): array
    {
        try {
            return $this->client($this->validatedConfig())->queryOrder($transId);
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    private function client(array $config): EpayClient { return new EpayClient($config['api_url'], $config['pid'], $config['key']); }
    private function notifyUrl(): string { return Route::has('plugin.epay_wxpay.notify') ? route('plugin.epay_wxpay.notify') : url('/plugin/epay_wxpay/notify'); }
    private function returnUrl(array $config, array $order): string { $configured = trim((string) ($config['return_url'] ?? '')); return $configured !== '' ? $configured : (string) ($order['return_url'] ?? route('client.invoices.show', $order['invoice_id'])); }
    private function epayPayload(array $data): array { return array_intersect_key($data, array_flip(['pid', 'trade_no', 'out_trade_no', 'type', 'name', 'money', 'trade_status', 'sign', 'sign_type'])); }
    private function validatedConfig(): array { $config = $this->getConfig(); foreach (['api_url', 'pid', 'key'] as $key) { if (trim((string) ($config[$key] ?? '')) === '') { throw new \InvalidArgumentException(self::TITLE . '未配置'); } } return $config; }
}
