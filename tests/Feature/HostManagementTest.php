<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HostManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_view_host_detail(): void
    {
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->get(route('client.hosts.show', $host))
            ->assertOk()
            ->assertSee($host->product->name);
    }

    public function test_client_can_create_renew_invoice(): void
    {
        Mail::fake();
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $this->assertDatabaseHas('invoice_items', [
            'type' => 'renewal',
            'rel_id' => $host->id,
        ]);
    }

    public function test_paid_renew_invoice_extends_host_due_date(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host(['next_due_date' => now()->addDays(10)]);
        $oldDueDate = $host->next_due_date->copy();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-001',
        ]));

        $this->assertTrue($host->fresh()->next_due_date->greaterThan($oldDueDate));
    }

    public function test_client_can_create_upgrade_invoice(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Pro VPS', 90);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect();

        $this->assertDatabaseHas('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
            'status' => 'Pending',
        ]);
        $this->assertDatabaseHas('invoice_items', ['type' => 'upgrade']);
    }

    public function test_disallowed_action_does_not_change_host_state(): void
    {
        $host = $this->host(['status' => 'Terminated']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'reboot'])
            ->assertRedirect(route('client.hosts.show', $host));

        $this->assertSame('Terminated', $host->fresh()->status);
    }

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $product = $this->product('Starter VPS', 50);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-HOST-' . random_int(1000, 9999),
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
            'next_due_date' => now()->addMonth(),
            'next_invoice_date' => now()->addDays(23),
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides))->load(['client', 'product']);
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'host-client-' . random_int(1000, 9999),
            'email' => 'host-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name, float $monthly): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '服务管理产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => $name,
            'description' => $name . ' 产品说明',
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => $monthly,
        ]);

        return $product;
    }

    private function installManualPay(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
        Plugin::query()->where('name', 'manual_pay')->update(['config' => []]);
    }
}
