<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Domain;
use App\Modules\Product\Models\DomainPricing;
use App\Modules\Product\Services\DomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(): View
    {
        $client = Auth::guard('client')->user();
        $domains = Domain::query()
            ->where('client_id', $client->id)
            ->latest('id')
            ->paginate(20);

        return view('theme::domains.index', compact('domains'));
    }

    public function register(Request $request, DomainService $domains): View
    {
        $availability = null;
        $domain = trim((string) $request->query('domain', ''));
        if ($domain !== '') {
            try {
                $availability = $domains->checkAvailability($domain);
            } catch (\Throwable) {
                $availability = false;
            }
        }

        return view('theme::domains.register', [
            'pricings' => DomainPricing::query()->where('active', true)->orderBy('tld')->get(),
            'domain' => $domain,
            'availability' => $availability,
        ]);
    }

    public function store(Request $request, DomainService $domains)
    {
        $client = Auth::guard('client')->user();
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'years' => ['required', 'integer', 'min:1', 'max:10'],
            'whois_privacy' => ['nullable', 'boolean'],
        ]);

        try {
            $domain = $domains->register($client, $data['domain'], (int) $data['years'], $request->boolean('whois_privacy'));
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors(['domain' => $exception->getMessage()]);
        }

        return redirect()->route('client.domains.show', $domain)->with('status', '域名注册订单已创建，请完成账单支付');
    }

    public function show(Domain $domain): View
    {
        $this->authorizeDomain($domain);

        return view('theme::domains.show', compact('domain'));
    }

    public function updateNameservers(Request $request, Domain $domain, DomainService $domains)
    {
        $this->authorizeDomain($domain);
        $data = $request->validate([
            'nameservers' => ['required', 'array', 'min:2', 'max:6'],
            'nameservers.*' => ['required', 'string', 'max:255'],
        ]);

        try {
            $domains->updateNameservers($domain, $data['nameservers']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['nameservers' => $exception->getMessage()]);
        }

        return redirect()->route('client.domains.show', $domain)->with('status', 'DNS 服务器已更新');
    }

    public function renew(Request $request, Domain $domain, DomainService $domains)
    {
        $this->authorizeDomain($domain);
        $data = $request->validate([
            'years' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $invoice = $domains->renew($domain, (int) $data['years']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['years' => $exception->getMessage()]);
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', '域名续费账单已生成');
    }

    private function authorizeDomain(Domain $domain): void
    {
        abort_unless((int) $domain->client_id === (int) Auth::guard('client')->id(), 403);
    }
}
