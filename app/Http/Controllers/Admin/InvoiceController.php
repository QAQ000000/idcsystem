<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = Invoice::query()
            ->with('client')
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('admin.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['client', 'items', 'accounts', 'order']);

        return view('admin.invoices.show', compact('invoice'));
    }

    public function markPaid(Request $request, Invoice $invoice, InvoiceService $invoices)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:50'],
            'trans_id' => ['nullable', 'string', 'max:100'],
        ]);

        $invoices->markAsPaid($invoice, $data['payment_method'] ?? 'manual', $data['trans_id'] ?? 'ADMIN-' . $invoice->id);

        return redirect()->route('admin.invoices.show', $invoice)->with('status', '账单已标记支付');
    }

    public function refund(Request $request, Invoice $invoice, InvoiceService $invoices)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $invoices->refund($invoice, (float) $data['amount']);

        return redirect()->route('admin.invoices.show', $invoice)->with('status', '退款已记录');
    }
}
