<?php

namespace Plugins\Gateway\AlipaySdk\src;

use InvalidArgumentException;

class AlipayClient
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';
    private const SANDBOX_GATEWAY = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    public function __construct(private array $config)
    {
    }

    public function buildRedirectUrl(array $order): string
    {
        $params = $this->baseParams() + [
            'method' => 'alipay.trade.page.pay',
            'return_url' => $order['return_url'] ?? null,
            'notify_url' => $order['notify_url'] ?? null,
            'biz_content' => json_encode([
                'out_trade_no' => (string) $order['out_trade_no'],
                'total_amount' => number_format((float) $order['total_amount'], 2, '.', ''),
                'subject' => (string) $order['subject'],
                'product_code' => 'FAST_INSTANT_TRADE_PAY',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $params['sign'] = $this->sign($params);

        return $this->gateway() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function verifyNotify(array $data): bool
    {
        $sign = (string) ($data['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        $payload = $this->signContent($data);
        $publicKey = openssl_pkey_get_public($this->normalizePublicKey((string) $this->config['alipay_public_key']));
        if (!$publicKey) {
            return false;
        }

        return openssl_verify($payload, base64_decode($sign) ?: '', $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public function refund(string $transId, float $amount): array
    {
        return [
            'success' => false,
            'message' => '支付宝官方退款接口尚未配置网关请求执行器',
            'trade_no' => $transId,
            'amount' => $amount,
        ];
    }

    public function query(string $transId): array
    {
        return [
            'success' => false,
            'message' => '支付宝官方查询接口尚未配置网关请求执行器',
            'trade_no' => $transId,
        ];
    }

    public function sign(array $params): string
    {
        $privateKey = openssl_pkey_get_private($this->normalizePrivateKey((string) $this->config['private_key']));
        if (!$privateKey) {
            throw new InvalidArgumentException('支付宝官方应用私钥格式无效');
        }

        $signature = '';
        if (!openssl_sign($this->signContent($params), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('支付宝官方签名失败');
        }

        return base64_encode($signature);
    }

    public function signContent(array $params): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if (in_array($key, ['sign', 'sign_type'], true) || $value === null || $value === '') {
                continue;
            }

            $filtered[$key] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value;
        }

        ksort($filtered);

        return urldecode(http_build_query($filtered, '', '&', PHP_QUERY_RFC3986));
    }

    private function baseParams(): array
    {
        return [
            'app_id' => (string) $this->config['app_id'],
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'version' => '1.0',
        ];
    }

    private function gateway(): string
    {
        return filter_var($this->config['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? self::SANDBOX_GATEWAY
            : self::GATEWAY;
    }

    private function normalizePrivateKey(string $key): string
    {
        return $this->normalizePem($key, 'PRIVATE KEY');
    }

    private function normalizePublicKey(string $key): string
    {
        return $this->normalizePem($key, 'PUBLIC KEY');
    }

    private function normalizePem(string $key, string $type): string
    {
        $key = trim(str_replace(["\r\n", "\r"], "\n", $key));
        if (str_contains($key, 'BEGIN ' . $type)) {
            return $key;
        }

        $body = trim(str_replace(["\n", ' '], '', $key));

        return "-----BEGIN {$type}-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END {$type}-----";
    }
}
