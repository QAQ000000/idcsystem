<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Services\TaxService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AffiliateService;
use App\Modules\User\Services\TwoFactorService;
use App\Services\ClientActivityService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function profile()
    {
        return view('theme::account.profile', [
            'client' => Auth::guard('client')->user(),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->get(),
            'countryOptions' => $this->countryOptions(),
            'stateOptions' => $this->stateOptions(),
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
            'country_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'state_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,10}$/'],
            'currency_id' => ['nullable', 'integer', Rule::exists('currencies', 'id')],
            'locale' => ['nullable', 'string', Rule::in(config('app.available_locales', ['zh_CN', 'en']))],
        ]);
        $data['currency_id'] = $data['currency_id'] ?? $client->currency_id;
        $data['locale'] = $data['locale'] ?? $client->locale ?? config('app.locale');
        $data['country_code'] = app(TaxService::class)->normalizeCountryCode($data['country_code'] ?? null);
        $data['state_code'] = app(TaxService::class)->normalizeStateCode($data['state_code'] ?? null);

        $changed = array_keys(array_filter(
            $data,
            fn ($value, $field): bool => (string) ($client->{$field} ?? '') !== (string) ($value ?? ''),
            ARRAY_FILTER_USE_BOTH
        ));
        $client->update($data);
        $request->session()->put('locale', $data['locale']);
        app()->setLocale($data['locale']);
        if ($changed !== []) {
            app(ClientActivityService::class)->log($client->fresh(), 'profile.updated', '账户资料已更新', [
                'fields' => $changed,
            ]);
        }

        return redirect()->route('client.account.profile')->with('status', __('messages.profile_updated'));
    }

    public function activity()
    {
        $client = Auth::guard('client')->user();

        return view('theme::account.activity', [
            'client' => $client,
            'activities' => $client->activities()
                ->latest('created_at')
                ->paginate(50),
        ]);
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

        return view('theme::account.security', [
            'client' => $client,
            'loginLogs' => $client->loginLogs()->latest('logged_in_at')->limit(10)->get(),
            'twoFactorSecret' => $secret,
            'twoFactorQrCodeUrl' => $qrCodeUrl,
        ]);
    }

    public function notifications(): View
    {
        $client = Auth::guard('client')->user();

        return view('theme::account.notifications', [
            'client' => $client,
            'events' => NotificationService::preferenceEvents(),
            'preferences' => $client->notification_preferences ?? [],
            'mandatory' => NotificationService::MANDATORY_NOTIFICATIONS,
        ]);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $events = array_keys(NotificationService::preferenceEvents());
        $data = $request->validate([
            'notifications' => ['nullable', 'array'],
            'notifications.*' => ['nullable', 'boolean'],
        ]);

        $submitted = $data['notifications'] ?? [];
        $preferences = [];
        foreach ($events as $event) {
            $preferences[$event] = NotificationService::isMandatory($event)
                ? true
                : filter_var($submitted[$event] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        Auth::guard('client')->user()->update([
            'notification_preferences' => $preferences,
        ]);

        return redirect()->route('client.account.notifications')->with('status', '通知偏好已更新');
    }

    public function recharge(Request $request, InvoiceService $invoices)
    {
        $client = Auth::guard('client')->user();

        if ($request->isMethod('get')) {
            return view('theme::account.recharge', [
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

        return view('theme::account.affiliate', [
            'client' => $client,
            'affiliate' => $affiliate->fresh(['commissions.referredClient', 'commissions.invoice']),
            'referralUrl' => route('client.register', ['ref' => $affiliate->code]),
        ]);
    }

    public function affiliateLeaderboard(AffiliateService $affiliates)
    {
        $client = Auth::guard('client')->user();
        $affiliate = $affiliates->getOrCreate($client);

        return view('theme::account.affiliate-leaderboard', [
            'affiliate' => $affiliate->fresh(),
            'commissionLeaders' => $affiliates->getLeaderboard('commission', 10),
            'referralLeaders' => $affiliates->getLeaderboard('referrals', 10),
            'clickLeaders' => $affiliates->getLeaderboard('clicks', 10),
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

    private function countryOptions(): array
    {
        return [
            'CN' => 'China',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'HK' => 'Hong Kong',
            'SG' => 'Singapore',
            'JP' => 'Japan',
            'DE' => 'Germany',
            'FR' => 'France',
            'AU' => 'Australia',
            'CA' => 'Canada',
        ];
    }

    private function stateOptions(): array
    {
        return [
            'CN' => [
                'BJ' => 'Beijing',
                'SH' => 'Shanghai',
                'GD' => 'Guangdong',
                'ZJ' => 'Zhejiang',
                'JS' => 'Jiangsu',
            ],
            'US' => [
                'CA' => 'California',
                'NY' => 'New York',
                'TX' => 'Texas',
                'WA' => 'Washington',
            ],
            'CA' => [
                'ON' => 'Ontario',
                'QC' => 'Quebec',
                'BC' => 'British Columbia',
            ],
            'AU' => [
                'NSW' => 'New South Wales',
                'VIC' => 'Victoria',
                'QLD' => 'Queensland',
            ],
        ];
    }
}
