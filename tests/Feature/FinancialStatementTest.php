<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\FinancialStatement;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\FinancialStatementService;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Affiliate;
use App\Modules\User\Models\AffiliateCommission;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_statement_service_generates_reconciliation_totals_and_breakdowns(): void
    {
        $client = $this->client();
        $product = $this->product('Statement VPS');
        $paid = $this->paidInvoice($client, 'INV-FS-001', 200, 'epay_alipay', '2026-05-10 10:00:00');
        $outside = $this->paidInvoice($client, 'INV-FS-002', 500, 'epay_wxpay', '2026-06-02 10:00:00');
        $unpaid = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-FS-003',
            'subtotal' => 300,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 300,
            'status' => 'Unpaid',
            'due_date' => now(),
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $paid->id,
            'type' => 'product',
            'description' => 'Statement VPS',
            'amount' => 200,
            'rel_id' => $product->id,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $outside->id,
            'type' => 'product',
            'description' => 'Outside VPS',
            'amount' => 500,
            'rel_id' => $product->id,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $unpaid->id,
            'type' => 'product',
            'description' => 'Unpaid VPS',
            'amount' => 300,
            'rel_id' => $product->id,
        ]);
        Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $paid->id,
            'type' => 'credit',
            'amount' => 200,
            'payment_method' => 'epay_alipay',
            'gateway_trans_id' => 'FS-PAID-001',
            'created_at' => '2026-05-10 10:01:00',
            'updated_at' => '2026-05-10 10:01:00',
        ]);
        Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $paid->id,
            'type' => 'refund',
            'amount' => 30,
            'payment_method' => 'epay_alipay',
            'gateway_trans_id' => 'FS-REFUND-001',
            'created_at' => '2026-05-11 10:01:00',
            'updated_at' => '2026-05-11 10:01:00',
        ]);
        $this->commission($client, $paid, 20, '2026-05-12 10:00:00');
        $this->commission($client, $outside, 99, '2026-06-01 10:00:00');

        $statement = app(FinancialStatementService::class)->generate(
            now()->setDate(2026, 5, 1),
            now()->setDate(2026, 5, 31)
        );

        $this->assertSame('200.00', (string) $statement->total_income);
        $this->assertSame('30.00', (string) $statement->total_refund);
        $this->assertSame('20.00', (string) $statement->total_commission);
        $this->assertSame('150.00', (string) $statement->net_income);
        $this->assertSame(1, $statement->paid_invoice_count);
        $this->assertSame(1, $statement->refund_count);
        $this->assertSame('epay_alipay', $statement->breakdown['income_by_payment_method'][0]['payment_method']);
        $this->assertEquals(200.0, $statement->breakdown['income_by_product'][0]['total']);
    }

    public function test_admin_can_generate_view_and_export_financial_statement(): void
    {
        $client = $this->client();
        $product = $this->product('Export VPS');
        $invoice = $this->paidInvoice($client, 'INV-FS-EXPORT', 88, 'balance', '2026-05-15 12:00:00');
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'type' => 'product',
            'description' => 'Export VPS',
            'amount' => 88,
            'rel_id' => $product->id,
        ]);
        Account::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => 'credit',
            'amount' => 88,
            'payment_method' => 'balance',
            'gateway_trans_id' => 'FS-EXPORT-001',
            'created_at' => '2026-05-15 12:01:00',
            'updated_at' => '2026-05-15 12:01:00',
        ]);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.financial-statements.index'))
            ->assertOk()
            ->assertSee('财务对账');

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.financial-statements.generate'), [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
            ]);
        $statement = FinancialStatement::query()->firstOrFail();
        $response->assertRedirect(route('admin.financial-statements.show', $statement));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.financial-statements.show', $statement))
            ->assertOk()
            ->assertSee('Export VPS')
            ->assertSee('88.00');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.financial-statements.export', $statement))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_monthly_statement_command_records_system_task_log(): void
    {
        $client = $this->client();
        $this->paidInvoice($client, 'INV-FS-CMD', 60, 'epay_wxpay', now()->subMonthNoOverflow()->startOfMonth()->addDays(2)->toDateTimeString());

        $this->artisan('financial:generate-monthly-statement')->assertExitCode(0);

        $this->assertSame(1, FinancialStatement::query()->count());
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'financial:generate-monthly-statement',
            'status' => 'success',
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'financial-admin-' . random_int(1000, 9999),
            'email' => 'financial-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'financial-client-' . random_int(1000, 9999),
            'email' => 'financial-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function paidInvoice(Client $client, string $number, float $total, string $paymentMethod, string $paidAt): Invoice
    {
        return Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => $number,
            'subtotal' => $total,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => $total,
            'status' => 'Paid',
            'payment_method' => $paymentMethod,
            'due_date' => $paidAt,
            'paid_at' => $paidAt,
        ]);
    }

    private function product(string $name): Product
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $group = ProductGroup::query()->firstOrCreate(['name' => '财务对账产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => $name,
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $currency->id,
            'monthly' => 50,
        ]);

        return $product;
    }

    private function commission(Client $client, Invoice $invoice, float $amount, string $paidAt): void
    {
        $affiliate = Affiliate::query()->firstOrCreate(
            ['client_id' => $client->id],
            [
                'code' => 'AFF' . random_int(1000, 9999),
                'status' => 'active',
            ]
        );

        AffiliateCommission::query()->create([
            'affiliate_id' => $affiliate->id,
            'referred_client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'type' => 'payment',
            'status' => 'paid',
            'paid_at' => $paidAt,
        ]);
    }
}
