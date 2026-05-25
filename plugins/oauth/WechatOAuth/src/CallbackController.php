<?php

namespace Plugins\Oauth\WechatOAuth\src;

use App\Modules\User\Services\AuthService;
use App\Plugins\Facades\Plugin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CallbackController
{
    public function redirect(Request $request): RedirectResponse
    {
        $plugin = $this->plugin();
        if (!$plugin) {
            return redirect()->route('client.login')->with('error', '微信登录暂不可用。');
        }

        $state = Str::random(40);
        $request->session()->put('oauth_wechat_state', $state);

        return redirect()->away($plugin->getAuthUrl($state));
    }

    public function callback(Request $request, AuthService $auth): RedirectResponse
    {
        $plugin = $this->plugin();
        $state = (string) $request->query('state', '');
        if (!$plugin || $state === '' || !hash_equals((string) $request->session()->pull('oauth_wechat_state'), $state)) {
            return redirect()->route('client.login')->withErrors(['email' => '微信登录状态已失效，请重试。']);
        }

        try {
            $token = $plugin->getAccessToken((string) $request->query('code', ''));
            $client = $auth->loginWithOAuth('wechat', $plugin->getUserInfo((string) ($token['access_token'] ?? '')) + $token);
        } catch (\Throwable $exception) {
            return redirect()->route('client.login')->withErrors(['email' => $exception->getMessage() ?: '微信登录失败。']);
        }

        Auth::guard('client')->login($client);
        $request->session()->regenerate();
        $auth->recordLogin($client);

        return redirect()->route('client.dashboard');
    }

    private function plugin(): ?WechatOAuthPlugin
    {
        $plugin = Plugin::type('oauth')->get('wechat_oauth');

        return $plugin instanceof WechatOAuthPlugin ? $plugin : null;
    }
}
