<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\HostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HostController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $hosts = Host::query()->with('product')->where('client_id', $client->id)->latest()->paginate(20);

        return view('client.hosts.index', compact('hosts'));
    }

    public function show(Host $host)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $host->client_id === (int) $client->id, 403);
        $host->load(['product', 'order.invoice']);

        return view('client.hosts.show', compact('host'));
    }

    public function renew(Request $request, Host $host, HostService $hosts)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $host->client_id === (int) $client->id, 403);
        $data = $request->validate([
            'billing_cycle' => ['nullable', 'string', 'max:50'],
        ]);

        $invoice = $hosts->renew($host, $data['billing_cycle'] ?? $host->billing_cycle ?? 'monthly');

        return redirect()->route('client.invoices.show', $invoice)->with('status', '续费账单已生成');
    }
}
