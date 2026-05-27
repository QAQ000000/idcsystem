<?php

namespace Plugins\Oauth\WechatOAuth\src;

use App\Plugins\Contracts\OauthProviderInterface;
use App\Plugins\Core\AbstractPlugin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class WechatOAuthPlugin extends AbstractPlugin implements OauthProviderInterface
{
    private const NAME = 'wechat_oauth';
    private ?array $lastToken = null;

    public function getName(): string { return self::NAME; }
    public function getTitle(): string { return '微信登录'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getType(): string { return 'oauth'; }
    public function getDescription(): string { return '通过微信 OAuth 网页授权登录客户中心'; }

    public function getAuthUrl(string $state): string
    {
        $config = $this->validatedConfig();
        $query = http_build_query([
            'appid' => $config['app_id'],
            'redirect_uri' => $this->redirectUrl($config),
            'response_type' => 'code',
            'scope' => $config['scope'] ?: 'snsapi_login',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://open.weixin.qq.com/connect/qrconnect?' . $query . '#wechat_redirect';
    }

    public function getAccessToken(string $code): array
    {
        $config = $this->validatedConfig();
        $mock = $this->mockUser($config);
        if ($mock !== null) {
            return $this->lastToken = [
                'access_token' => $mock['access_token'] ?? 'mock-access-token',
                'refresh_token' => $mock['refresh_token'] ?? null,
                'expires_in' => $mock['expires_in'] ?? 7200,
                'openid' => $mock['openid'] ?? $mock['id'] ?? 'mock-openid',
                'unionid' => $mock['unionid'] ?? null,
            ];
        }

        return $this->lastToken = Http::asJson()->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
            'appid' => $config['app_id'],
            'secret' => $config['app_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        ])->throw()->json();
    }

    public function getUserInfo(string $accessToken): array
    {
        $config = $this->validatedConfig();
        $mock = $this->mockUser($config);
        if ($mock !== null) {
            return $mock + ['access_token' => $accessToken];
        }

        return Http::asJson()->get('https://api.weixin.qq.com/sns/userinfo', [
            'access_token' => $accessToken,
            'openid' => $this->lastToken['openid'] ?? request('openid'),
            'lang' => 'zh_CN',
        ])->throw()->json() + ['access_token' => $accessToken];
    }

    private function validatedConfig(): array
    {
        $config = $this->getConfig();
        foreach (['app_id', 'app_secret'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new \InvalidArgumentException('微信登录未配置');
            }
        }

        return $config + ['scope' => 'snsapi_login'];
    }

    private function redirectUrl(array $config): string
    {
        return trim((string) ($config['redirect_url'] ?? '')) !== ''
            ? (string) $config['redirect_url']
            : (Route::has('oauth.wechat.callback') ? route('oauth.wechat.callback') : url('/oauth/wechat/callback'));
    }

    private function mockUser(array $config): ?array
    {
        $mock = trim((string) ($config['mock_user'] ?? ''));
        if ($mock === '') {
            return null;
        }

        $decoded = json_decode($mock, true);

        return is_array($decoded) ? $decoded : null;
    }
}
