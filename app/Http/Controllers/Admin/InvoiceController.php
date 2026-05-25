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
        $status = $this->queryString($request, 'status');
        $keyword = $this->queryString($request, 'keyword');

        $invoices = Invoice::query()
            ->with('client')
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->when($keyword, function ($query, string $keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('invoice_number', 'like', "%{$keyword}%")
                        ->orWhereHas('client', function ($query) use ($keyword) {
                            $query->where('username', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });
                });
            })
            ->latest()
            ->paginate(20);

        return view('admin.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice, InvoiceService $invoices)
    {
        $invoice->load(['client', 'items', 'accounts', 'order']);
        $remainingRefundableAmount = $invoices->remainingRefundableAmount($invoice);

        return view('admin.invoices.show', compact('invoice', 'remainingRefundableAmount'));
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

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
