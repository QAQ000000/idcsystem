<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Services\AdminAuditService;
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

    public function markPaid(Request $request, Invoice $invoice, InvoiceService $invoices, AdminAuditService $audit)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:50'],
            'trans_id' => ['nullable', 'string', 'max:100'],
        ]);

        $success = $invoices->markAsPaid($invoice, $data['payment_method'] ?? 'manual', $data['trans_id'] ?? 'ADMIN-INVOICE-' . $invoice->id);
        $audit->record($request, 'invoice.mark_paid', $invoice, $success ? 'success' : 'failed', [
            'payment_method' => $data['payment_method'] ?? 'manual',
            'trans_id' => $data['trans_id'] ?? 'ADMIN-INVOICE-' . $invoice->id,
            'invoice_status' => $invoice->fresh()->status,
        ], $success ? null : '当前账单状态不允许标记支付');

        if (!$success) {
            return redirect()->route('admin.invoices.show', $invoice)->with('error', '当前账单状态不允许标记支付');
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', '账单已标记支付');
    }

    public function refund(Request $request, Invoice $invoice, InvoiceService $invoices, AdminAuditService $audit)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $success = $invoices->refund($invoice, (float) $data['amount']);
        $audit->record($request, 'invoice.refund', $invoice, $success ? 'success' : 'failed', [
            'amount' => (float) $data['amount'],
            'invoice_status' => $invoice->fresh()->status,
        ], $success ? null : '当前账单状态或金额不允许退款');

        if (!$success) {
            return redirect()->route('admin.invoices.show', $invoice)->with('error', '当前账单状态或金额不允许退款');
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', '退款已记录');
    }
}
