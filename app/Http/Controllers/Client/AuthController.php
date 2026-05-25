<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AuthService;
use App\Modules\User\Services\TwoFactorService;
use App\Plugins\Facades\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Plugins\Oauth\WechatOAuth\src\WechatOAuthPlugin;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::guard('client')->check()) {
            return redirect()->route('client.dashboard');
        }

        return view('client.auth.login');
    }

    public function login(Request $request, AuthService $auth)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $client = $auth->login($data['email'], $data['password']);

        if (!$client) {
            return back()->withErrors(['email' => '账号或密码错误，或账号未启用。'])->onlyInput('email');
        }

        if ($client->two_factor_enabled) {
            $request->session()->put(AuthService::TWO_FACTOR_SESSION_KEY, $client->id);

            return redirect()->route('client.login.2fa');
        }

        Auth::guard('client')->login($client);
        $request->session()->regenerate();
        $auth->recordLogin($client);

        return redirect()->route('client.dashboard');
    }

    public function showTwoFactorForm(Request $request)
    {
        if (!$request->session()->has(AuthService::TWO_FACTOR_SESSION_KEY)) {
            return redirect()->route('client.login');
        }

        return view('client.auth.two-factor');
    }

    public function verifyTwoFactor(Request $request, AuthService $auth, TwoFactorService $twoFactor)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $clientId = (int) $request->session()->get(AuthService::TWO_FACTOR_SESSION_KEY);
        $client = Client::query()->find($clientId);

        if (!$client || !$client->isActive() || !$client->two_factor_enabled || !$client->two_factor_secret) {
            $request->session()->forget(AuthService::TWO_FACTOR_SESSION_KEY);

            return redirect()->route('client.login')->withErrors(['email' => '两步验证会话已失效，请重新登录。']);
        }

        if (!$twoFactor->verify((string) $client->two_factor_secret, $data['code'])) {
            return back()->withErrors(['code' => '验证码不正确。']);
        }

        $request->session()->forget(AuthService::TWO_FACTOR_SESSION_KEY);
        Auth::guard('client')->login($client);
        $request->session()->regenerate();
        $auth->recordLogin($client);

        return redirect()->route('client.dashboard');
    }

    public function showRegisterForm()
    {
        return view('client.auth.register');
    }

    public function redirectToWechatOAuth(Request $request)
    {
        $plugin = Plugin::type('oauth')->get('wechat_oauth');
        if (!$plugin instanceof WechatOAuthPlugin) {
            return redirect()->route('client.login')->with('error', '微信登录暂不可用。');
        }

        $state = Str::random(40);
        $request->session()->put('oauth_wechat_state', $state);

        return redirect()->away($plugin->getAuthUrl($state));
    }

    public function handleWechatOAuthCallback(Request $request, AuthService $auth)
    {
        $plugin = Plugin::type('oauth')->get('wechat_oauth');
        $state = (string) $request->query('state', '');
        if (!$plugin instanceof WechatOAuthPlugin || $state === '' || !hash_equals((string) $request->session()->pull('oauth_wechat_state'), $state)) {
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

    public function register(Request $request, AuthService $auth)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:clients,username'],
            'email' => ['required', 'email', 'max:100', 'unique:clients,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $client = $auth->register($data);
        Auth::guard('client')->login($client);
        $request->session()->regenerate();

        return redirect()->route('verification.notice')->with('status', '注册成功，请先验证邮箱。');
    }

    public function verificationNotice()
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        if ($client->email_verified_at !== null) {
            return redirect()->route('client.dashboard');
        }

        return view('client.auth.verify-email', [
            'client' => $client,
        ]);
    }

    public function verifyEmail(Request $request, int $id, AuthService $auth)
    {
        $client = Client::query()->findOrFail($id);

        if (!hash_equals((string) $request->route('hash'), sha1((string) $client->email))) {
            abort(403);
        }

        $auth->verifyEmail($client);

        Auth::guard('client')->login($client->fresh());
        $request->session()->regenerate();
        $auth->recordLogin($client->fresh());

        return redirect()->route('client.dashboard')->with('status', '邮箱验证成功，账号已激活。');
    }

    public function resendVerification(Request $request, AuthService $auth)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        if ($client->email_verified_at !== null) {
            return redirect()->route('client.dashboard');
        }

        $auth->sendEmailVerification($client->fresh());

        return back()->with('status', '验证邮件已重新发送。');
    }

    public function logout(Request $request)
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->forget(AuthService::TWO_FACTOR_SESSION_KEY);

        return redirect()->route('client.login');
    }
}
