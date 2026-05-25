<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AffiliateService;
use App\Modules\User\Services\TwoFactorService;
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

    public function security(TwoFactorService $twoFactor)
    {
        $client = Auth::guard('client')->user();
        $secret = null;
        $qrCodeUrl = null;

        if (!$client->two_factor_enabled) {
            $secret = session('two_factor_setup_secret');
            if (!$secret) {
                $secret = $twoFactor->generateSecret();
                session(['two_factor_setup_secret' => $secret]);
            }

            $qrCodeUrl = $twoFactor->qrCodeUrl($client, $secret);
        }

        return view('client.account.security', [
            'client' => $client,
            'loginLogs' => $client->loginLogs()->latest('logged_in_at')->limit(10)->get(),
            'twoFactorSecret' => $secret,
            'twoFactorQrCodeUrl' => $qrCodeUrl,
        ]);
    }

    public function recharge(Request $request, InvoiceService $invoices)
    {
        $client = Auth::guard('client')->user();

        if ($request->isMethod('get')) {
            return view('client.account.recharge', [
                'client' => $client,
            ]);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999'],
        ]);

        $invoice = $invoices->generateRecharge($client, (float) $data['amount']);

        return redirect()->route('client.invoices.show', $invoice)->with('status', '充值账单已生成，请完成支付');
    }

    public function affiliate(AffiliateService $affiliates)
    {
        $client = Auth::guard('client')->user();
        $affiliate = $affiliates->getOrCreate($client);

        return view('client.account.affiliate', [
            'client' => $client,
            'affiliate' => $affiliate->fresh(['commissions.referredClient', 'commissions.invoice']),
            'referralUrl' => route('client.register', ['ref' => $affiliate->code]),
        ]);
    }

    public function withdrawAffiliate(Request $request, AffiliateService $affiliates)
    {
        $client = Auth::guard('client')->user();
        $affiliate = $affiliates->getOrCreate($client);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        if (!$affiliates->withdraw($affiliate, (float) $data['amount'])) {
            return redirect()->route('client.affiliate')->with('error', '当前可提现佣金不足，或账户状态不允许提现');
        }

        return redirect()->route('client.affiliate')->with('status', '佣金已转入账户余额');
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

    public function enableTwoFactor(Request $request, TwoFactorService $twoFactor)
    {
        $client = Auth::guard('client')->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $secret = (string) session('two_factor_setup_secret');
        if ($secret === '' || !$twoFactor->verify($secret, $data['code'])) {
            return back()->withErrors(['code' => '验证码不正确，无法启用两步验证。']);
        }

        $client->update([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
        ]);
        $request->session()->forget('two_factor_setup_secret');

        return redirect()->route('client.account.security')->with('status', '两步验证已启用。');
    }

    public function disableTwoFactor(Request $request)
    {
        $client = Auth::guard('client')->user();
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $freshClient = Client::query()->whereKey($client->id)->first();
        if (!$freshClient || !Hash::check($data['password'], $freshClient->password)) {
            return back()->withErrors(['password' => '密码不正确，无法关闭两步验证。']);
        }

        $freshClient->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        return redirect()->route('client.account.security')->with('status', '两步验证已关闭。');
    }
}
