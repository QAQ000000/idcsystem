<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Client;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function profile()
    {
        return view('client.account.profile', [
            'client' => Auth::guard('client')->user(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $client = Auth::guard('client')->user();
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:100'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $client->update($data);

        return redirect()->route('client.account.profile')->with('status', '资料已更新');
    }

    public function security()
    {
        $client = Auth::guard('client')->user();

        return view('client.account.security', [
            'client' => $client,
            'loginLogs' => $client->loginLogs()->latest('logged_in_at')->limit(10)->get(),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $client = Auth::guard('client')->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $freshClient = Client::query()->whereKey($client->id)->first();
        if (!$freshClient || !$freshClient->isActive() || !Hash::check($data['current_password'], $freshClient->password)) {
            return back()->withErrors(['current_password' => '当前密码不正确。']);
        }

        $freshClient->update(['password' => Hash::make($data['password'])]);

        app(NotificationService::class)->notifyClient($freshClient, 'password_changed', [
            'client_name' => $freshClient->username,
        ]);

        return redirect()->route('client.account.security')->with('status', '密码已修改，当前会话保持登录');
    }
}
