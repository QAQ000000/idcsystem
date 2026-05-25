<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceReceipt;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_apply_receipt_for_paid_invoice(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 'Paid');

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.receipt.create', $invoice))
            ->assertOk()
            ->assertSee($invoice->invoice_number);

        $this->actingAs($client, 'client')
            ->post(route('client.invoices.receipt.store', $invoice), [
                'type' => 'vat',
                'title' => 'IDC 测试公司',
                'tax_number' => 'TAX123',
                'bank_name' => '测试银行',
                'bank_account' => '622200000000',
                'company_address' => '测试地址',
                'company_phone' => '010-12345678',
                'email' => 'receipt@example.com',
            ])
            ->assertRedirect(route('client.invoices.show', $invoice));

        $this->assertDatabaseHas('invoice_receipts', [
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'vat',
            'status' => 'pending',
            'title' => 'IDC 测试公司',
        ]);
    }

    public function test_client_cannot_apply_receipt_for_unpaid_or_duplicate_invoice(): void
    {
        $client = $this->client();
        $unpaid = $this->invoice($client, 'Unpaid');
        $paid = $this->invoice($client, 'Paid', 'INV-RECEIPT-PAID-DUP');
        InvoiceReceipt::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $paid->id,
            'type' => 'plain',
            'title' => '已申请',
            'email' => 'old@example.com',
            'status' => 'pending',
        ]);

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.receipt.create', $unpaid))
            ->assertForbidden();

        $this->actingAs($client, 'client')
            ->post(route('client.invoices.receipt.store', $paid), [
                'type' => 'plain',
                'title' => '重复申请',
                'email' => 'new@example.com',
            ])
            ->assertRedirect(route('client.invoices.show', $paid));

        $this->assertDatabaseMissing('invoice_receipts', [
            'invoice_id' => $paid->id,
            'title' => '重复申请',
        ]);
    }

    public function test_client_cannot_apply_receipt_for_other_client_invoice(): void
    {
        $client = $this->client('receipt-owner');
        $other = $this->client('receipt-other');
        $invoice = $this->invoice($other, 'Paid');

        $this->actingAs($client, 'client')
            ->get(route('client.invoices.receipt.create', $invoice))
            ->assertForbidden();
    }

    public function test_admin_can_mark_receipt_issued(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $invoice = $this->invoice($client, 'Paid');
        $receipt = InvoiceReceipt::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'plain',
            'title' => 'IDC 测试公司',
            'email' => 'receipt@example.com',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.invoice-receipts.index'))
            ->assertOk()
            ->assertSee('IDC 测试公司');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.invoice-receipts.update', $receipt), [
                'status' => 'issued',
                'admin_notes' => '已开具电子发票',
            ])
            ->assertRedirect();

        $this->assertSame('issued', $receipt->fresh()->status);
        $this->assertNotNull($receipt->fresh()->issued_at);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'invoice_receipt.update',
            'result' => 'success',
        ]);
    }

    private function client(string $prefix = 'receipt-client'): Client
    {
        return Client::query()->create([
            'username' => $prefix . '-' . random_int(1000, 9999),
            'email' => $prefix . '-' . random_int(1000, 9999) . '@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
    }

    private function invoice(Client $client, string $status, string $number = 'INV-RECEIPT-1'): Invoice
    {
        return Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => $number . '-' . random_int(1000, 9999),
            'subtotal' => 100,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 100,
            'status' => $status,
            'due_date' => now()->addDays(7),
            'paid_at' => $status === 'Paid' ? now() : null,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'receipt-admin-' . random_int(1000, 9999),
            'email' => 'receipt-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
