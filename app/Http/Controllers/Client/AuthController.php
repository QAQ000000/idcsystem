<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        Auth::guard('client')->login($client);
        $request->session()->regenerate();

        return redirect()->route('client.dashboard');
    }

    public function showRegisterForm()
    {
        return view('client.auth.register');
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

        return redirect()->route('client.login');
    }
}
