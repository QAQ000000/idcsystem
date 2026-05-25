<?php

namespace Plugins\Server\Cpanel\src;

use App\Plugins\Contracts\ServerModuleInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CpanelPlugin extends AbstractPlugin implements ServerModuleInterface
{
    public function getName(): string
    {
        return 'cpanel';
    }

    public function getTitle(): string
    {
        return 'cPanel/WHM';
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
        return '通过 WHM JSON API 自动管理 cPanel 虚拟主机账户。';
    }

    public function createAccount(array $params): array
    {
        try {
            $username = $this->username($params);
            $password = (string) ($params['password'] ?? Str::password(16));
            $domain = trim((string) ($params['domain'] ?? ''));
            $plan = trim((string) ($params['plan'] ?? $params['package'] ?? ''));

            if ($domain === '' || $plan === '') {
                return ['success' => false, 'message' => 'cPanel 开通参数缺少域名或套餐。'];
            }

            $result = $this->client($params)->createAccount($username, $domain, $password, $plan);
            if (($result['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'cPanel 账户开通失败。',
                ];
            }

            return [
                'success' => true,
                'username' => $username,
                'password' => $password,
                'server_id' => $result['server_id'] ?? $username,
            ];
        } catch (Throwable $exception) {
            $this->logException('createAccount', $exception);

            return ['success' => false, 'message' => 'cPanel 账户开通异常。'];
        }
    }

    public function suspendAccount(array $params): bool
    {
        try {
            return $this->client($params)->suspendAccount(
                $this->requiredUsername($params),
                (string) ($params['reason'] ?? '')
            );
        } catch (Throwable $exception) {
            $this->logException('suspendAccount', $exception);

            return false;
        }
    }

    public function unsuspendAccount(array $params): bool
    {
        try {
            return $this->client($params)->unsuspendAccount($this->requiredUsername($params));
        } catch (Throwable $exception) {
            $this->logException('unsuspendAccount', $exception);

            return false;
        }
    }

    public function terminateAccount(array $params): bool
    {
        try {
            return $this->client($params)->terminateAccount($this->requiredUsername($params));
        } catch (Throwable $exception) {
            $this->logException('terminateAccount', $exception);

            return false;
        }
    }

    public function changePassword(array $params): bool
    {
        try {
            $newPassword = (string) ($params['new_password'] ?? $params['password'] ?? '');
            if ($newPassword === '') {
                return false;
            }

            return $this->client($params)->changePassword($this->requiredUsername($params), $newPassword);
        } catch (Throwable $exception) {
            $this->logException('changePassword', $exception);

            return false;
        }
    }

    public function getUsageStats(array $params): array
    {
        try {
            $usage = $this->client($params)->getAccountUsage($this->requiredUsername($params));

            return [
                'cpu' => 0.0,
                'memory' => 0.0,
                'disk' => $this->percent($usage['disk_used'] ?? 0, $usage['disk_limit'] ?? 0),
                'bandwidth' => $this->percent($usage['bandwidth_used'] ?? 0, $usage['bandwidth_limit'] ?? 0),
            ];
        } catch (Throwable $exception) {
            $this->logException('getUsageStats', $exception);

            return [
                'cpu' => 0.0,
                'memory' => 0.0,
                'disk' => 0.0,
                'bandwidth' => 0.0,
            ];
        }
    }

    private function client(array $params): CpanelClient
    {
        return new CpanelClient($this->serverConfig($params));
    }

    private function serverConfig(array $params): array
    {
        $config = $params['server_config'] ?? $this->getConfig();

        return is_array($config) ? $config : [];
    }

    private function username(array $params): string
    {
        $given = trim((string) ($params['username'] ?? ''));
        if ($given !== '') {
            return Str::limit(Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $given) ?: 'cpuser'), 16, '');
        }

        $domain = trim((string) ($params['domain'] ?? ''));
        $prefix = explode('.', $domain)[0] ?: 'site';
        $base = Str::limit(Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'site'), 8, '');

        return $base . Str::lower(Str::random(4));
    }

    private function requiredUsername(array $params): string
    {
        $username = trim((string) ($params['username'] ?? ''));
        if ($username === '') {
            throw new \InvalidArgumentException('cPanel 账户用户名不能为空。');
        }

        return $username;
    }

    private function percent(float|int|string|null $used, float|int|string|null $limit): float
    {
        $used = max(0.0, (float) $used);
        $limit = max(0.0, (float) $limit);
        if ($limit <= 0) {
            return 0.0;
        }

        return round(min(100.0, ($used / $limit) * 100), 2);
    }

    private function logException(string $action, Throwable $exception): void
    {
        Log::warning('cPanel server module failed: ' . $action, [
            'message' => $exception->getMessage(),
        ]);
    }
}
