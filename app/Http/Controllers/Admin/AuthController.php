<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\User\Services\TwoFactorService;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const TWO_FACTOR_PENDING_KEY = 'admin_2fa_pending_id';
    private const TWO_FACTOR_SETUP_KEY = 'admin_2fa_setup_secret';

    public function showLoginForm()
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request, AdminAuditService $audit)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $admin = AdminUser::query()
            ->where('username', $data['username'])
            ->orWhere('email', $data['username'])
            ->first();

        if (!$admin || !Hash::check($data['password'], $admin->password) || !$admin->isActive()) {
            $audit->record($request, 'admin.login', $admin, 'failed', [
                'username' => $data['username'],
            ], '管理员账号或密码错误。');

            return back()->withErrors(['username' => '管理员账号或密码错误。'])->onlyInput('username');
        }

        if ($admin->two_factor_enabled) {
            $request->session()->put(self::TWO_FACTOR_PENDING_KEY, $admin->id);

            return redirect()->route('admin.login.2fa');
        }

        return $this->completeLogin($request, $admin, $audit);
    }

    public function showTwoFactor(): View|RedirectResponse
    {
        if (!session(self::TWO_FACTOR_PENDING_KEY)) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.two-factor');
    }

    public function verifyTwoFactor(Request $request, TwoFactorService $twoFactor, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $admin = AdminUser::query()->find((int) $request->session()->get(self::TWO_FACTOR_PENDING_KEY));
        if (!$admin || !$admin->isActive() || !$admin->two_factor_enabled || !$admin->two_factor_secret) {
            $request->session()->forget(self::TWO_FACTOR_PENDING_KEY);

            return redirect()->route('admin.login');
        }

        if (!$twoFactor->verify((string) $admin->two_factor_secret, $data['code'])) {
            $audit->record($request, 'admin.login_2fa', $admin, 'failed', [
                'username' => $admin->username,
            ], '管理员两步验证失败。');

            return back()->withErrors(['code' => '验证码错误']);
        }

        $request->session()->forget(self::TWO_FACTOR_PENDING_KEY);

        return $this->completeLogin($request, $admin, $audit);
    }

    public function twoFactorSetup(Request $request, TwoFactorService $twoFactor): View
    {
        $admin = $request->user('admin');
        $secret = null;
        $qrCodeUrl = null;

        if (!$admin->two_factor_enabled) {
            $secret = (string) $request->session()->get(self::TWO_FACTOR_SETUP_KEY);
            if ($secret === '') {
                $secret = $twoFactor->generateSecret();
                $request->session()->put(self::TWO_FACTOR_SETUP_KEY, $secret);
            }

            $qrCodeUrl = $this->adminQrCodeUrl($twoFactor, $admin, $secret);
        }

        return view('admin.auth.two-factor-setup', compact('admin', 'secret', 'qrCodeUrl'));
    }

    public function enableTwoFactor(Request $request, TwoFactorService $twoFactor, AdminAuditService $audit): RedirectResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $secret = (string) $request->session()->get(self::TWO_FACTOR_SETUP_KEY);
        if ($secret === '' || !$twoFactor->verify($secret, $data['code'])) {
            return back()->withErrors(['code' => '验证码不正确，无法启用两步验证。']);
        }

        $admin->update([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
        ]);
        $request->session()->forget(self::TWO_FACTOR_SETUP_KEY);
        $audit->record($request, 'admin.2fa_enable', $admin, 'success', ['username' => $admin->username]);

        return redirect()->route('admin.profile.2fa')->with('status', '管理员两步验证已启用。');
    }

    public function disableTwoFactor(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            return back()->withErrors(['password' => '密码不正确，无法关闭两步验证。']);
        }

        $admin->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);
        $audit->record($request, 'admin.2fa_disable', $admin, 'success', ['username' => $admin->username]);

        return redirect()->route('admin.profile.2fa')->with('status', '管理员两步验证已关闭。');
    }

    public function logout(Request $request)
    {
        $admin = $request->user('admin');

        if ($admin) {
            app(AdminAuditService::class)->record($request, 'admin.logout', $admin, 'success', [
                'username' => $admin->username,
            ]);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function completeLogin(Request $request, AdminUser $admin, AdminAuditService $audit): RedirectResponse
    {
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();
        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);
        $audit->record($request, 'admin.login', $admin, 'success', [
            'username' => $admin->username,
        ]);

        return redirect()->intended(route('admin.dashboard'));
    }

    private function adminQrCodeUrl(TwoFactorService $twoFactor, AdminUser $admin, string $secret): string
    {
        $clientLike = new \App\Modules\User\Models\Client([
            'email' => $admin->email,
        ]);

        return $twoFactor->qrCodeUrl($clientLike, $secret);
    }
}
