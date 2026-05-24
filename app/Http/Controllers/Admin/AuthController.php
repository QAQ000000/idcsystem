<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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
}
