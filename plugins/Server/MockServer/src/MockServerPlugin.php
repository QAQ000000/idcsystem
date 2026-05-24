<?php

namespace Plugins\Server\MockServer\src;

use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Core\AbstractPlugin;

class MockServerPlugin extends AbstractPlugin implements ServerModuleInterface
{
    public function getName(): string
    {
        return 'mock_server';
    }

    public function getTitle(): string
    {
        return 'Mock Server';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getType(): string
    {
        return 'server';
    }

    public function getDescription(): string
    {
        return '内置模拟服务器模块，用于本地开通、暂停、解除暂停、终止和重置密码流程验证。';
    }

    public function createAccount(array $params): array
    {
        $config = $this->getConfig();

        if (($config['fail_create'] ?? false) === true) {
            return [
                'success' => false,
                'message' => 'MockServer 模拟开通失败',
            ];
        }

        $hostId = (int) ($params['host_id'] ?? 0);

        return [
            'success' => true,
            'username' => $params['username'] ?: 'mock' . $hostId,
            'password' => $params['password'] ?: 'MockPass-' . $hostId,
            'server_id' => $params['server_id'] ?? $hostId,
        ];
    }

    public function suspendAccount(array $params): bool
    {
        return true;
    }

    public function unsuspendAccount(array $params): bool
    {
        if (($this->getConfig()['fail_unsuspend'] ?? false) === true) {
            return false;
        }

        return true;
    }

    public function terminateAccount(array $params): bool
    {
        return true;
    }

    public function changePassword(array $params): bool
    {
        return !empty($params['new_password']);
    }

    public function getUsageStats(array $params): array
    {
        if (($this->getConfig()['fail_usage'] ?? false) === true) {
            throw new \RuntimeException('MockServer 模拟用量采集失败');
        }

        $seed = max(1, (int) ($params['host_id'] ?? 1));

        return [
            'cpu' => min(100, 20 + $seed % 30),
            'memory' => 512 + ($seed % 4) * 256,
            'disk' => 20 + ($seed % 5) * 10,
            'bandwidth' => 100 + ($seed % 6) * 50,
        ];
    }
}
