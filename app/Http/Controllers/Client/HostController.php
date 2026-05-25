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

        return view('theme::hosts.index', compact('hosts'));
    }

    public function show(Host $host)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $host->client_id === (int) $client->id, 403);
        $host->load(['product.pricings', 'order.invoice', 'customFieldValues.field', 'actionLogs' => fn ($query) => $query->latest()->limit(10), 'upgrades']);

        return view('theme::hosts.show', [
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
        abort_if(in_array($host->status, ['Terminated', 'Cancelled'], true), 403);
        $data = $request->validate([
            'billing_cycle' => ['nullable', 'string', 'max:50', 'in:' . implode(',', $hosts->availableCycles())],
        ]);

        try {
            $invoice = $hosts->renew($host, $data['billing_cycle'] ?? $host->billing_cycle ?? 'monthly');
        } catch (\RuntimeException $exception) {
            return redirect()->route('client.hosts.show', $host)->withErrors([
                'billing_cycle' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', '续费账单已生成');
    }

    public function upgrade(Request $request, Host $host, HostService $hosts)
    {
        $this->authorizeHost($host);
        abort_unless($host->status === 'Active', 403);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $targetProduct = Product::query()
            ->where('hidden', false)
            ->where('retired', false)
            ->findOrFail((int) $data['product_id']);

        try {
            $invoice = $hosts->createUpgradeInvoice($host->load('product', 'client'), $targetProduct);
        } catch (\RuntimeException $exception) {
            return redirect()->route('client.hosts.show', $host)->withErrors([
                'product_id' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', '升级/降配账单已生成');
    }

    public function action(Request $request, Host $host, HostService $hosts)
    {
        $this->authorizeHost($host);
        $data = $request->validate([
            'action' => ['required', 'string', 'in:provision,reboot,reset_password,cancel_auto_renew'],
        ]);

        $ok = match ($data['action']) {
            'provision' => $this->canSelfProvision($host) ? $hosts->provision($host) : false,
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

    private function canSelfProvision(Host $host): bool
    {
        if ($host->status !== 'Pending') {
            return false;
        }

        $host->loadMissing('order.invoice');

        // 客户侧只能重试已付款服务的开通，避免未支付订单直接绕过账单流程。
        return $host->order?->status === 'Paid'
            && (!$host->order?->invoice || $host->order->invoice->status === 'Paid');
    }
}
