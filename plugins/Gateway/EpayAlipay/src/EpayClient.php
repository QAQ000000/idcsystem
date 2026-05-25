<?php

namespace Plugins\Gateway\EpayAlipay\src;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class EpayClient
{
    public function __construct(
        private string $apiUrl,
        private string $pid,
        private string $key,
    ) {
        $this->apiUrl = $this->normalizeApiUrl($apiUrl);
    }

    public function buildRedirectUrl(array $params): string
    {
        $payload = array_merge($params, ['pid' => $this->pid]);
        $payload['sign'] = $this->sign($payload);
        $payload['sign_type'] = 'MD5';

        return $this->apiUrl . 'submit.php?' . http_build_query($payload);
    }

    public function verifyNotify(array $data): bool
    {
        $sign = $data['sign'] ?? null;
        if (!is_scalar($sign) || trim((string) $sign) === '') {
            return false;
        }

        return hash_equals(strtolower((string) $sign), $this->sign($data));
    }

    public function queryOrder(string $tradeNo): array
    {
        return $this->request($this->apiUrl . 'api.php?act=order', [
            'pid' => $this->pid,
            'key' => $this->key,
            'trade_no' => $tradeNo,
        ]);
    }

    public function refund(string $tradeNo, float $amount): array
    {
        return $this->request($this->apiUrl . 'api.php?act=refund', [
            'pid' => $this->pid,
            'key' => $this->key,
            'trade_no' => $tradeNo,
            'money' => number_format($amount, 2, '.', ''),
        ]);
    }

    public function referenceSign(array $params): string
    {
        return $this->sign($params);
    }

    private function sign(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if (in_array($key, ['sign', 'sign_type'], true) || $value === '' || $value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $parts[$key] = $key . '=' . $value;
            }
        }

        ksort($parts);

        return strtolower(md5(implode('&', $parts) . $this->key));
    }

    private function request(string $url, array $post = []): array
    {
        $response = Http::withOptions(['verify' => false])->timeout(10)->asForm()->post($url, $post);
        $json = $response->json();
        if (is_array($json)) {
            return $json;
        }

        parse_str($response->body(), $parsed);

        return $parsed ?: [
            'code' => $response->successful() ? 1 : -1,
            'raw' => $response->body(),
        ];
    }

    private function normalizeApiUrl(string $apiUrl): string
    {
        $apiUrl = trim($apiUrl);
        if (!preg_match('/^https?:\/\//i', $apiUrl)) {
            throw new InvalidArgumentException('易支付接口地址必须以 http:// 或 https:// 开头。');
        }

        return rtrim($apiUrl, '/') . '/';
    }
}
