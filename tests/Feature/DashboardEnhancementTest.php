<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Modules\Finance\Models\Credit;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardEnhancementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_renewals_invoices_credits_and_announcements(): void
    {
        $client = $this->client();
        $product = $this->product();
        $host = $this->host($client, $product);
        $invoice = $this->invoice($client, 'INV-DASH-1');
        $invoice->update(['status' => 'Unpaid', 'due_date' => now()->addDays(5)]);
        Credit::query()->create([
            'client_id' => $client->id,
            'type' => 'add',
            'amount' => 88,
            'balance' => 188,
            'description' => '测试充值',
        ]);
        Announcement::query()->create([
            'title' => '仪表盘公告',
            'content' => '客户可见',
            'type' => 'info',
            'active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        $this->actingAs($client, 'client')
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('本月消费')
            ->assertSee('即将到期的服务')
            ->assertSee($host->domain)
            ->assertSee('未付账单')
            ->assertSee($invoice->invoice_number)
            ->assertSee('最近余额变动')
            ->assertSee('仪表盘公告');
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'dashboard-client',
            'email' => 'dashboard-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(): Product
    {
        $group = ProductGroup::query()->create(['name' => '仪表盘产品组']);

        return Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Dashboard VPS',
            'type' => 'vps',
            'stock_control' => false,
        ]);
    }

    private function host(Client $client, Product $product): Host
    {
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-DASH-1',
            'status' => 'Paid',
            'amount' => 100,
            'currency_id' => $client->currency_id,
        ]);

        return Host::query()->create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain' => 'dash.example.com',
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 100,
            'recurring_amount' => 100,
            'status' => 'Active',
            'next_due_date' => now()->addDays(10),
        ]);
    }

    private function invoice(Client $client, string $number): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => $number,
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'status' => 'Paid',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'type' => 'product',
            'description' => 'Dashboard invoice item',
            'amount' => 100,
        ]);

        return $invoice;
    }
}
