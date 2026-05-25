<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Plugins\Core\PluginManager;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\User\Services\ClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvoiceController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $invoices = Invoice::query()->where('client_id', $client->id)->latest()->paginate(20);

        return view('theme::invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice, PluginManager $plugins)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $invoice->client_id === (int) $client->id, 403);
        $invoice->load(['items', 'accounts', 'receipts']);

        return view('theme::invoices.show', [
            'invoice' => $invoice,
            'gateways' => $plugins->type('gateway')->enabled(),
            'client' => $client,
        ]);
    }

    public function pay(Request $request, Invoice $invoice, PaymentService $payments)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $invoice->client_id === (int) $client->id, 403);
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:100'],
        ]);

        $result = $payments->processPayment($invoice, $data['payment_method'], [
            'return_url' => route('client.invoices.show', $invoice),
        ]);

        if (!($result['success'] ?? false)) {
            return redirect()->route('client.invoices.show', $invoice)->withErrors([
                'payment' => $result['message'] ?? '支付发起失败',
            ]);
        }

        if (($result['pay_type'] ?? null) === 'redirect' && !empty($result['payment_url'])) {
            return redirect()->away((string) $result['payment_url']);
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', $result['message'] ?? '支付已发起');
    }

    public function payWithCredit(Invoice $invoice, InvoiceService $invoices)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $invoice->client_id === (int) $client->id, 403);

        if ($invoice->status !== 'Unpaid') {
            return redirect()->route('client.invoices.show', $invoice)->with('error', '当前账单状态不允许余额支付');
        }

        if (!app(ClientService::class)->canAfford($client->fresh(), (float) $invoice->total)) {
            return redirect()->route('client.invoices.show', $invoice)->with('error', '账户余额不足，无法支付该账单');
        }

        if (!$invoices->payWithCredit($invoice)) {
            return redirect()->route('client.invoices.show', $invoice)->with('error', '余额支付失败，请刷新后重试');
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', '余额支付成功');
    }

    public function callback(Request $request, string $gateway, PaymentService $payments)
    {
        $handled = $payments->handleCallback($gateway, $request->all());

        return response($handled ? 'ok' : 'failed', $handled ? 200 : 400);
    }
}
