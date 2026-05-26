<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_report_index_links_to_all_reports(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('收入趋势')
            ->assertSee('客户增长')
            ->assertSee('服务状态')
            ->assertSee('产品排行');
    }

    public function test_revenue_report_uses_paid_invoice_totals(): void
    {
        $client = $this->client();
        Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-REPORT-001',
            'subtotal' => 100,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 100,
            'status' => 'Paid',
            'paid_at' => now()->setDate(2026, 5, 10),
            'due_date' => now(),
        ]);
        Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-REPORT-002',
            'subtotal' => 200,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 200,
            'status' => 'Unpaid',
            'due_date' => now(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.revenue'))
            ->assertOk()
            ->assertSee('2026-05')
            ->assertSee('100');
    }

    public function test_host_status_report_counts_current_statuses(): void
    {
        $host = $this->host(['status' => 'Active']);
        $this->host(['status' => 'Suspended'], $host->client);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.hosts'))
            ->assertOk()
            ->assertSee('Active')
            ->assertSee('Suspended');
    }

    public function test_product_report_counts_paid_product_items(): void
    {
        $client = $this->client();
        $product = $this->product('Report VPS');
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-PRODUCT-001',
            'subtotal' => 120,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 120,
            'status' => 'Paid',
            'paid_at' => now(),
            'due_date' => now(),
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'type' => 'product',
            'description' => 'Report VPS',
            'amount' => 120,
            'rel_id' => $product->id,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.reports.products'))
            ->assertOk()
            ->assertSee('Report VPS')
            ->assertSee('120.00');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'report-admin-' . random_int(1000, 9999),
            'email' => 'report-admin-' . random_int(1000, 9999) . '@example.com',
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
            'username' => 'report-client-' . random_int(1000, 9999),
            'email' => 'report-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '报表产品']);
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
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => 50,
        ]);

        return $product;
    }

    private function host(array $overrides = [], ?Client $client = null): Host
    {
        $client ??= $this->client();
        $product = $this->product('Host Report VPS ' . random_int(1000, 9999));
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-REPORT-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $client->currency_id,
        ]);

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides));
    }
}
