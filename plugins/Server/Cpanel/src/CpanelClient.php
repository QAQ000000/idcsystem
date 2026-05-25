<?php

namespace Plugins\Server\Cpanel\src;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class CpanelClient
{
    private string $baseUrl;

    private string $username;

    private string $apiToken;

    public function __construct(array $config)
    {
        $host = trim((string) ($config['host'] ?? ''));
        $this->username = trim((string) ($config['username'] ?? ''));
        $this->apiToken = trim((string) ($config['api_token'] ?? ''));

        if ($host === '' || $this->username === '' || $this->apiToken === '') {
            throw new InvalidArgumentException('cPanel/WHM 配置不完整。');
        }

        $scheme = filter_var($config['ssl'] ?? true, FILTER_VALIDATE_BOOL) ? 'https' : 'http';
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = trim($host, "/ \t\n\r\0\x0B");
        $port = (int) ($config['port'] ?? 2087);
        $port = $port > 0 ? $port : 2087;
        $this->baseUrl = sprintf('%s://%s:%d/json-api', $scheme, $host, $port);
    }

    public function createAccount(string $username, string $domain, string $password, string $plan): array
    {
        $payload = $this->post('createacct', [
            'user' => $username,
            'domain' => $domain,
            'password' => $password,
            'plan' => $plan,
            'cpmod' => 'paper_lantern',
        ]);

        return [
            'success' => $this->isSuccessful($payload),
            'message' => $this->message($payload),
            'username' => $username,
            'password' => $password,
            'server_id' => $username,
            'raw' => $payload,
        ];
    }

    public function suspendAccount(string $username, string $reason = ''): bool
    {
        return $this->isSuccessful($this->post('suspendacct', [
            'user' => $username,
            'reason' => $reason,
        ]));
    }

    public function unsuspendAccount(string $username): bool
    {
        return $this->isSuccessful($this->post('unsuspendacct', [
            'user' => $username,
        ]));
    }

    public function terminateAccount(string $username): bool
    {
        return $this->isSuccessful($this->post('removeacct', [
            'user' => $username,
        ]));
    }

    public function changePassword(string $username, string $newPassword): bool
    {
        return $this->isSuccessful($this->post('passwd', [
            'user' => $username,
            'password' => $newPassword,
        ]));
    }

    public function getAccountUsage(string $username): array
    {
        $payload = $this->get('accountsummary', [
            'user' => $username,
        ]);

        $account = $payload['acct'][0] ?? $payload['data']['acct'][0] ?? [];
        if (!is_array($account)) {
            $account = [];
        }

        return [
            'disk_used' => (float) ($account['diskused'] ?? $account['disk_used'] ?? 0),
            'disk_limit' => (float) ($account['disklimit'] ?? $account['disk_limit'] ?? 0),
            'bandwidth_used' => (float) ($account['totalbytes'] ?? $account['bwusage'] ?? 0),
            'bandwidth_limit' => (float) ($account['bwlimit'] ?? $account['bandwidth_limit'] ?? 0),
            'raw' => $payload,
        ];
    }

    private function get(string $endpoint, array $query = []): array
    {
        return $this->decode($this->request()->get($this->url($endpoint), $query)->json());
    }

    private function post(string $endpoint, array $data = []): array
    {
        return $this->decode($this->request()->asForm()->post($this->url($endpoint), $data)->json());
    }

    private function request(): PendingRequest
    {
        return Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'whm ' . $this->username . ':' . $this->apiToken,
            ]);
    }

    private function url(string $endpoint): string
    {
        return $this->baseUrl . '/' . ltrim($endpoint, '/');
    }

    private function decode(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    private function isSuccessful(array $payload): bool
    {
        $result = $payload['metadata']['result']
            ?? $payload['status']
            ?? $payload['result'][0]['status']
            ?? $payload['data']['result']
            ?? null;

        return in_array($result, [1, '1', true, 'success', 'Success'], true);
    }

    private function message(array $payload): string
    {
        return (string) (
            $payload['metadata']['reason']
            ?? $payload['statusmsg']
            ?? $payload['result'][0]['statusmsg']
            ?? ''
        );
    }
}
