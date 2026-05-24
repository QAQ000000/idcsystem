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

    public function test_paid_renew_invoice_is_not_applied_twice(): void
    {
        Mail::fake();
        $this->installManualPay();
        $host = $this->host(['next_due_date' => now()->addDays(10)]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        $invoice = \App\Modules\Finance\Models\Invoice::query()->findOrFail($invoiceId);

        $this->assertTrue(app(\App\Modules\Finance\Services\InvoiceService::class)->markAsPaid($invoice, 'manual_pay', 'HOST-RENEW-ONCE'));
        $firstDueDate = $host->fresh()->next_due_date->copy();
        $this->assertFalse(app(\App\Modules\Finance\Services\InvoiceService::class)->markAsPaid($invoice->fresh(), 'manual_pay', 'HOST-RENEW-TWICE'));

        $this->assertTrue($host->fresh()->next_due_date->equalTo($firstDueDate));
        $this->assertDatabaseMissing('accounts', ['gateway_trans_id' => 'HOST-RENEW-TWICE']);
    }

    public function test_paid_renew_invoice_unsuspends_suspended_host_through_server_module(): void
    {
        Mail::fake();
        $this->installManualPay();
        $this->installMockServer();
        $host = $this->host(['status' => 'Suspended']);
        $host->product->update(['server_type' => 'mock_server']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-UNSUSPEND-001',
        ]));

        $this->assertSame('Active', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'unsuspend',
        ]);
    }

    public function test_paid_renew_invoice_keeps_host_suspended_when_server_unsuspend_fails(): void
    {
        Mail::fake();
        $this->installManualPay();
        $this->installMockServer(['fail_unsuspend' => true]);
        $host = $this->host(['status' => 'Suspended']);
        $host->product->update(['server_type' => 'mock_server']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertRedirect();

        $invoiceId = InvoiceItem::query()->where('type', 'renewal')->where('rel_id', $host->id)->value('invoice_id');
        $this->assertTrue(app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoiceId,
            'amount' => 50.00,
            'status' => 'paid',
            'trans_id' => 'HOST-RENEW-UNSUSPEND-FAIL-001',
        ]));

        $this->assertSame('Suspended', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'unsuspend_failed',
            'message' => '服务器模块解除暂停失败',
        ]);
    }

    public function test_client_cannot_create_zero_amount_renew_invoice(): void
    {
        Mail::fake();
        $host = $this->host();

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'annually'])
            ->assertRedirect(route('client.hosts.show', $host))
            ->assertSessionHasErrors('billing_cycle');

        $this->assertDatabaseMissing('invoice_items', [
            'type' => 'renewal',
            'rel_id' => $host->id,
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'renew_invoice_failed',
        ]);
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

    public function test_client_downgrade_completes_without_zero_amount_payment_dead_end(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Small VPS', 30);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertRedirect();

        $host->refresh();
        $this->assertSame($target->id, $host->product_id);
        $this->assertSame('30.00', (string) $host->recurring_amount);
        $this->assertDatabaseHas('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
            'type' => 'downgrade',
            'amount' => 0,
            'status' => 'Completed',
        ]);
        $this->assertDatabaseHas('invoices', [
            'client_id' => $host->client_id,
            'total' => 0,
            'status' => 'Paid',
            'payment_method' => 'no_payment_required',
        ]);
        $this->assertDatabaseHas('invoice_items', ['type' => 'downgrade']);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'downgrade_completed',
        ]);
    }

    public function test_client_cannot_upgrade_to_hidden_product(): void
    {
        Mail::fake();
        $host = $this->host();
        $target = $this->product('Hidden VPS', 90);
        $target->update(['hidden' => true]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $target->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('upgrades', [
            'host_id' => $host->id,
            'to_product_id' => $target->id,
        ]);
    }

    public function test_disallowed_action_does_not_change_host_state(): void
    {
        $host = $this->host(['status' => 'Terminated']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'reboot'])
            ->assertRedirect(route('client.hosts.show', $host));

        $this->assertSame('Terminated', $host->fresh()->status);
    }

    public function test_client_cannot_self_provision_unpaid_pending_host(): void
    {
        $host = $this->host(['status' => 'Pending'], ['status' => 'Pending']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'provision'])
            ->assertRedirect(route('client.hosts.show', $host));

        $this->assertSame('Pending', $host->fresh()->status);
        $this->assertDatabaseMissing('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'provision',
        ]);
    }

    public function test_client_disallowed_action_writes_service_failure_log(): void
    {
        $host = $this->host(['status' => 'Pending'], ['status' => 'Pending']);

        $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'suspend'])
            ->assertRedirect(route('client.hosts.show', $host));

        $this->assertSame('Pending', $host->fresh()->status);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'suspend_failed',
        ]);
    }

    private function host(array $overrides = [], array $orderOverrides = []): Host
    {
        $client = $this->client();
        $product = $this->product('Starter VPS', 50);
        $order = Order::query()->create(array_merge([
            'client_id' => $client->id,
            'order_number' => 'ORD-HOST-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $client->currency_id,
        ], $orderOverrides));

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

    private function installMockServer(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        Plugin::query()->where('name', 'mock_server')->update(['config' => $config]);
    }
}
