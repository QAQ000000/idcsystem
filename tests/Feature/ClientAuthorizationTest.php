<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\CartService;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_cannot_access_or_operate_another_clients_host(): void
    {
        [$owner, $other] = [$this->client('owner-host'), $this->client('other-host')];
        $host = $this->host($owner);

        $this->actingAs($other, 'client')
            ->get(route('client.hosts.show', $host))
            ->assertForbidden();

        $this->actingAs($other, 'client')
            ->post(route('client.hosts.renew', $host), ['billing_cycle' => 'monthly'])
            ->assertForbidden();

        $this->actingAs($other, 'client')
            ->post(route('client.hosts.upgrade', $host), ['product_id' => $this->product('Target VPS')->id])
            ->assertForbidden();

        $this->actingAs($other, 'client')
            ->post(route('client.hosts.action', $host), ['action' => 'reboot'])
            ->assertForbidden();
    }

    public function test_client_cannot_access_or_pay_another_clients_invoice(): void
    {
        [$owner, $other] = [$this->client('owner-invoice'), $this->client('other-invoice')];
        $invoice = $this->invoice($owner);

        $this->actingAs($other, 'client')
            ->get(route('client.invoices.show', $invoice))
            ->assertForbidden();

        $this->actingAs($other, 'client')
            ->post(route('client.invoices.pay', $invoice), ['payment_method' => 'manual_pay'])
            ->assertForbidden();
    }

    public function test_client_cannot_access_or_reply_to_another_clients_ticket(): void
    {
        [$owner, $other] = [$this->client('owner-ticket'), $this->client('other-ticket')];
        $ticket = $this->ticket($owner);

        $this->actingAs($other, 'client')
            ->get(route('client.tickets.show', $ticket))
            ->assertForbidden();

        $this->actingAs($other, 'client')
            ->post(route('client.tickets.reply', $ticket), ['message' => 'cross client reply'])
            ->assertForbidden();
    }

    public function test_cart_remove_only_affects_current_clients_cart(): void
    {
        [$owner, $other] = [$this->client('owner-cart'), $this->client('other-cart')];
        $product = $this->product('Cart VPS');
        $cart = app(CartService::class);

        $cart->add($owner, $product, ['billing_cycle' => 'monthly']);
        $cart->add($other, $product, ['billing_cycle' => 'monthly']);

        $this->actingAs($other, 'client')
            ->delete(route('client.cart.remove', 1))
            ->assertRedirect(route('client.cart.index'));

        $this->assertCount(1, $cart->getCart($owner)['items']);
        $this->assertCount(0, $cart->getCart($other)['items']);
    }

    private function client(string $name): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => $name,
            'email' => $name . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '客户授权测试产品']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => $name,
            'type' => 'vps',
            'hidden' => false,
            'retired' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => 50,
        ]);

        return $product;
    }

    private function host(Client $client): Host
    {
        $product = $this->product('Owner VPS');
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-AUTH-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $client->currency_id,
        ]);

        return Host::query()->create([
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
        ]);
    }

    private function invoice(Client $client): Invoice
    {
        return Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-AUTH-' . random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => 50,
            'status' => 'Unpaid',
            'due_date' => now()->addDays(7),
        ]);
    }

    private function ticket(Client $client): Ticket
    {
        $department = TicketDepartment::query()->create(['name' => '授权测试部门']);
        $status = TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);

        return Ticket::query()->create([
            'ticket_number' => 'TICAUTH' . random_int(1000, 9999),
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => '授权测试工单',
            'message' => '测试内容',
            'priority' => 'Medium',
        ]);
    }
}
