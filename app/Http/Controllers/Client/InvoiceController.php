<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
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

        return view('client.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $invoice->client_id === (int) $client->id, 403);
        $invoice->load(['items', 'accounts']);

        return view('client.invoices.show', compact('invoice'));
    }

    public function pay(Request $request, Invoice $invoice, InvoiceService $invoices)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $invoice->client_id === (int) $client->id, 403);
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $invoices->markAsPaid($invoice, $data['payment_method'] ?? 'manual', 'CLIENT-' . $invoice->id);

        return redirect()->route('client.invoices.show', $invoice)->with('status', '账单已支付');
    }
}
