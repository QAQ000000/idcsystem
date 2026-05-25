<?php

namespace Plugins\Gateway\WechatPay\src;

use InvalidArgumentException;

class WechatPayClient
{
    public function __construct(private array $config)
    {
    }

    public function buildH5Payment(array $order): array
    {
        $this->assertConfigured();
        $outTradeNo = (string) $order['out_trade_no'];
        $amount = max(1, (int) round((float) $order['amount'] * 100));
        $payload = [
            'appid' => (string) $this->config['app_id'],
            'mchid' => (string) $this->config['mch_id'],
            'description' => (string) $order['description'],
            'out_trade_no' => $outTradeNo,
            'notify_url' => (string) $order['notify_url'],
            'amount' => ['total' => $amount, 'currency' => 'CNY'],
            'scene_info' => [
                'payer_client_ip' => (string) ($order['client_ip'] ?? '127.0.0.1'),
                'h5_info' => ['type' => 'Wap'],
            ],
        ];

        return [
            'mweb_url' => 'https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id='
                . rawurlencode('mock_' . hash('sha256', $outTradeNo . '|' . $amount)),
            'payload' => $payload,
            'authorization' => $this->authorization('POST', '/v3/pay/transactions/h5', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    public function verifyNotify(array $data): bool
    {
        $timestamp = (string) ($data['wechatpay_timestamp'] ?? '');
        $nonce = (string) ($data['wechatpay_nonce'] ?? '');
        $signature = (string) ($data['wechatpay_signature'] ?? '');
        $body = (string) ($data['wechatpay_body'] ?? '');
        $publicKey = (string) ($data['wechatpay_public_key'] ?? $this->config['wechatpay_public_key'] ?? '');

        if ($timestamp === '' || $nonce === '' || $signature === '' || $body === '' || $publicKey === '') {
            return false;
        }

        $message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $key = openssl_pkey_get_public($this->normalizePem($publicKey, 'PUBLIC KEY'));
        if (!$key) {
            return false;
        }

        return openssl_verify($message, base64_decode($signature) ?: '', $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function decryptResource(array $data): array
    {
        $resource = $data['resource'] ?? null;
        if (!is_array($resource)) {
            return $data;
        }

        if (($resource['algorithm'] ?? '') !== 'AEAD_AES_256_GCM') {
            return $data;
        }

        $ciphertext = base64_decode((string) ($resource['ciphertext'] ?? ''), true);
        $nonce = (string) ($resource['nonce'] ?? '');
        $associatedData = (string) ($resource['associated_data'] ?? '');
        $key = (string) $this->config['api_v3_key'];
        if ($ciphertext === false || strlen($ciphertext) <= 16 || strlen($key) !== 32) {
            return $data;
        }

        $tag = substr($ciphertext, -16);
        $ciphertext = substr($ciphertext, 0, -16);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);
        $decoded = is_string($plain) ? json_decode($plain, true) : null;

        return is_array($decoded) ? $decoded : $data;
    }

    public function refund(string $transId, float $amount): array
    {
        return [
            'success' => false,
            'message' => '微信支付退款接口尚未配置网关请求执行器',
            'transaction_id' => $transId,
            'amount' => $amount,
        ];
    }

    public function query(string $transId): array
    {
        return [
            'success' => false,
            'message' => '微信支付查询接口尚未配置网关请求执行器',
            'transaction_id' => $transId,
        ];
    }

    public function authorization(string $method, string $url, string $body): string
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = strtoupper($method) . "\n{$url}\n{$timestamp}\n{$nonce}\n{$body}\n";
        $signature = $this->sign($message);

        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $this->config['mch_id'],
            $nonce,
            $signature,
            $timestamp,
            $this->config['cert_serial']
        );
    }

    public function sign(string $message): string
    {
        $privateKey = openssl_pkey_get_private($this->normalizePem((string) $this->config['private_key'], 'PRIVATE KEY'));
        if (!$privateKey) {
            throw new InvalidArgumentException('微信支付商户私钥格式无效');
        }

        $signature = '';
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('微信支付签名失败');
        }

        return base64_encode($signature);
    }

    private function assertConfigured(): void
    {
        foreach (['mch_id', 'api_v3_key', 'cert_serial', 'private_key', 'app_id'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new InvalidArgumentException('微信支付官方未配置');
            }
        }
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
