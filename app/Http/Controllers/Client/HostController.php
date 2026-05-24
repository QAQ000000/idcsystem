<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Services\HostService;
use App\Modules\Product\Models\Product;
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
        $host->load(['product.pricings', 'order.invoice', 'actionLogs' => fn ($query) => $query->latest()->limit(10), 'upgrades']);

        return view('client.hosts.show', [
            'host' => $host,
            'cycles' => app(HostService::class)->availableCycles(),
            'upgradeProducts' => Product::query()
                ->where('hidden', false)
                ->where('retired', false)
                ->where('id', '!=', $host->product_id)
                ->orderBy('name')
                ->get(),
        ]);
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

    public function upgrade(Request $request, Host $host, HostService $hosts)
    {
        $this->authorizeHost($host);
        abort_unless($host->status === 'Active', 403);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $invoice = $hosts->createUpgradeInvoice($host->load('product', 'client'), Product::query()->findOrFail((int) $data['product_id']));

        return redirect()->route('client.invoices.show', $invoice)->with('status', '升级/降配账单已生成');
    }

    public function action(Request $request, Host $host, HostService $hosts)
    {
        $this->authorizeHost($host);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:provision,suspend,unsuspend,reboot,reset_password,cancel_auto_renew'],
        ]);

        $ok = match ($data['action']) {
            'provision' => $host->status === 'Pending' && $hosts->provision($host),
            'suspend' => $host->status === 'Active' && $hosts->suspend($host, '客户自助暂停'),
            'unsuspend' => $host->status === 'Suspended' && $hosts->unsuspend($host),
            'reboot' => $hosts->reboot($host),
            'reset_password' => $hosts->resetPassword($host),
            'cancel_auto_renew' => $hosts->cancelAutoRenew($host),
            default => false,
        };

        return redirect()->route('client.hosts.show', $host)->with(
            $ok ? 'status' : 'error',
            $ok ? '服务操作已提交' : '当前服务状态不允许该操作'
        );
    }

    private function authorizeHost(Host $host): void
    {
        $client = Auth::guard('client')->user();

        abort_unless($client && (int) $host->client_id === (int) $client->id, 403);
    }
}
