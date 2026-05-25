<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceReceipt;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InvoiceReceiptController extends Controller
{
    public function create(Invoice $invoice)
    {
        $client = Auth::guard('client')->user();
        abort_unless((int) $invoice->client_id === (int) $client->id, 403);
        abort_unless($this->canApply($invoice), 403);

        return view('client.invoices.receipt', [
            'invoice' => $invoice,
            'client' => $client,
        ]);
    }

    public function store(Request $request, Invoice $invoice, NotificationService $notifications)
    {
        $client = Auth::guard('client')->user();
        abort_unless((int) $invoice->client_id === (int) $client->id, 403);

        if (!$this->canApply($invoice)) {
            return redirect()->route('client.invoices.show', $invoice)->with('error', '当前账单不允许申请发票');
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['plain', 'vat'])],
            'title' => ['required', 'string', 'max:255'],
            'tax_number' => ['nullable', 'required_if:type,vat', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $receipt = InvoiceReceipt::query()->create($data + [
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
        ]);

        $notifications->notifyClient($client, 'invoice_receipt_submitted', [
            'client_name' => $client->username,
            'invoice_number' => $invoice->invoice_number,
            'receipt_title' => $receipt->title,
        ]);

        return redirect()->route('client.invoices.show', $invoice)->with('status', '发票申请已提交');
    }

    private function canApply(Invoice $invoice): bool
    {
        return $invoice->status === 'Paid'
            && !$invoice->receipts()->whereIn('status', ['pending', 'processing', 'issued'])->exists();
    }
}
