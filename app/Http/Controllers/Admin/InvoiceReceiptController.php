<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\InvoiceReceipt;
use App\Services\AdminAuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceReceiptController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->queryString($request, 'status');

        $receipts = InvoiceReceipt::query()
            ->with(['client', 'invoice'])
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.invoice-receipts.index', [
            'receipts' => $receipts,
            'filters' => ['status' => $status],
        ]);
    }

    public function update(Request $request, InvoiceReceipt $receipt, NotificationService $notifications, AdminAuditService $audit)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['processing', 'issued', 'rejected'])],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $receipt->update([
            'status' => $data['status'],
            'admin_notes' => $data['admin_notes'] ?? null,
            'issued_at' => $data['status'] === 'issued' ? now() : $receipt->issued_at,
        ]);

        if ($data['status'] === 'issued' && $receipt->client) {
            $notifications->notifyClient($receipt->client, 'invoice_receipt_issued', [
                'client_name' => $receipt->client->username,
                'invoice_number' => $receipt->invoice?->invoice_number,
                'receipt_title' => $receipt->title,
            ]);
        }

        $audit->record($request, 'invoice_receipt.update', $receipt, 'success', [
            'receipt_id' => $receipt->id,
            'status' => $data['status'],
        ]);

        return back()->with('status', '发票申请已更新');
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
