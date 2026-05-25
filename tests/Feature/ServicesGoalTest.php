<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\CartService;
use App\Modules\Order\Services\HostService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Services\PricingService;
use InvalidArgumentException;
use App\Modules\Product\Services\ProductService;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketService;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_pricing_order_invoice_host_ticket_and_cart_services_work_together(): void
    {
        $currency = Currency::create([
            'code' => 'CNY',
            'prefix' => '¥',
            'suffix' => '',
            'exchange_rate' => 1,
            'is_default' => true,
        ]);

        $client = Client::create([
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'secret',
            'status' => 1,
            'currency_id' => $currency->id,
        ]);

        $group = ProductGroup::create(['name' => 'VPS']);
        $productService = new ProductService();
        $product = $productService->create([
            'group_id' => $group->id,
            'name' => 'Starter VPS',
            'type' => 'vps',
            'stock_control' => true,
            'stock_qty' => 2,
        ]);

        $this->assertTrue($productService->checkStock($product));
        $this->assertTrue($productService->decrementStock($product));
        $this->assertCount(1, $productService->getAvailableProducts());

        $pricingService = new PricingService();
        $pricingService->setPricing('product', $product->id, $currency->id, [
            'monthly' => 50,
            'monthly_setup' => 10,
        ]);
        $this->assertSame(60.0, $pricingService->calculatePrice($product, 'monthly'));

        $invoiceService = new InvoiceService();
        $orderService = new OrderService($pricingService, $invoiceService);
        $order = $orderService->create($client, [[
            'product' => $product,
            'billing_cycle' => 'monthly',
            'currency_id' => $currency->id,
        ]]);

        $this->assertSame('Pending', $order->status);
        $this->assertSame('60.00', (string) $order->amount);
        $this->assertCount(1, $order->hosts);
        $this->assertNotNull($order->invoice);

        $this->assertTrue($orderService->markAsPaid($order->fresh(), 'manual', 'TXN-1'));
        $this->assertSame('Paid', $order->fresh()->status);
        $this->assertSame('Paid', $order->invoice->fresh()->status);

        $hostService = new HostService($pricingService, $invoiceService);
        $renewInvoice = $hostService->renew($order->hosts()->first(), 'monthly');
        $this->assertSame('Unpaid', $renewInvoice->status);

        TicketStatus::create(['name' => 'Open', 'is_default' => true]);
        TicketStatus::create(['name' => 'Answered']);
        TicketStatus::create(['name' => 'Closed']);
        $department = TicketDepartment::create(['name' => '技术支持']);
        $ticketService = new TicketService();
        $ticket = $ticketService->create($client, $department->id, 'Need help', 'Please check my VPS.');
        $reply = $ticketService->reply($ticket, 'admin', 1, 'Checked.');
        $this->assertSame($ticket->id, $reply->ticket_id);
        $this->assertTrue($ticketService->rate($ticket->fresh(), 5));
        $this->assertTrue($ticketService->close($ticket->fresh()));

        $cartService = new CartService($pricingService, $orderService);
        $cart = $cartService->add($client, $product->fresh(), ['billing_cycle' => 'monthly']);
        $this->assertSame(1, $cart['items'][0]['id']);
        $cartOrder = $cartService->checkout($client);
        $this->assertSame('Pending', $cartOrder->status);
        $this->assertSame([], $cartService->getCart($client)['items']);

        $billingService = new BillingService($hostService);
        $this->assertIsInt($billingService->sendDueReminders());
    }

    public function test_pricing_service_rejects_amount_above_database_capacity(): void
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $group = ProductGroup::query()->create(['name' => '价格边界产品组']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => '超大价格测试产品',
            'type' => 'vps',
            'stock_control' => false,
            'stock_qty' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('价格超出允许范围');

        app(PricingService::class)->setPricing('product', $product->id, $currency->id, [
            'monthly' => 100000000,
        ]);
    }

    public function test_recurring_invoice_generation_skips_hosts_with_unpaid_renewal_invoice(): void
    {
        $currency = Currency::create([
            'code' => 'CNY',
            'prefix' => '¥',
            'suffix' => '',
            'exchange_rate' => 1,
            'is_default' => true,
        ]);

        $client = Client::create([
            'username' => 'billing-client',
            'email' => 'billing-client@example.com',
            'password' => 'secret',
            'status' => 1,
            'currency_id' => $currency->id,
        ]);

        $group = ProductGroup::create(['name' => 'VPS']);
        $product = Product::create([
            'group_id' => $group->id,
            'name' => 'Billing VPS',
            'type' => 'vps',
            'status' => 'Active',
        ]);

        $pricingService = new PricingService();
        $pricingService->setPricing('product', $product->id, $currency->id, [
            'monthly' => 50,
        ]);

        $order = Order::create([
            'client_id' => $client->id,
            'order_number' => 'ORD-BILLING-1',
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $currency->id,
        ]);

        $host = Host::create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'next_due_date' => now()->addDays(5),
            'next_invoice_date' => now()->subDay(),
            'status' => 'Active',
            'auto_renew' => true,
        ]);

        $billingService = new BillingService(new HostService($pricingService, new InvoiceService()));

        $this->assertSame(1, $billingService->generateRecurringInvoices());
        $this->assertSame(0, $billingService->generateRecurringInvoices());
        $this->assertSame(1, InvoiceItem::query()
            ->where('type', 'renewal')
            ->where('rel_id', $host->id)
            ->count());
    }

    public function test_recurring_invoice_generation_skips_inactive_clients(): void
    {
        $currency = Currency::create([
            'code' => 'CNY',
            'prefix' => '¥',
            'suffix' => '',
            'exchange_rate' => 1,
            'is_default' => true,
        ]);

        $client = Client::create([
            'username' => 'inactive-billing-client',
            'email' => 'inactive-billing-client@example.com',
            'password' => 'secret',
            'status' => 2,
            'currency_id' => $currency->id,
        ]);

        $group = ProductGroup::create(['name' => 'VPS']);
        $product = Product::create([
            'group_id' => $group->id,
            'name' => 'Inactive Billing VPS',
            'type' => 'vps',
            'status' => 'Active',
        ]);

        $pricingService = new PricingService();
        $pricingService->setPricing('product', $product->id, $currency->id, [
            'monthly' => 50,
        ]);

        $order = Order::create([
            'client_id' => $client->id,
            'order_number' => 'ORD-INACTIVE-BILLING-1',
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $currency->id,
        ]);

        $host = Host::create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'next_due_date' => now()->addDays(5),
            'next_invoice_date' => now()->subDay(),
            'status' => 'Active',
            'auto_renew' => true,
        ]);

        $billingService = new BillingService(new HostService($pricingService, new InvoiceService()));

        $this->assertSame(0, $billingService->generateRecurringInvoices());
        $this->assertDatabaseMissing('invoice_items', [
            'type' => 'renewal',
            'rel_id' => $host->id,
        ]);
        $this->assertDatabaseHas('host_action_logs', [
            'host_id' => $host->id,
            'action' => 'renew_invoice_failed',
            'message' => '客户账号状态不允许自动续费',
        ]);
    }
}
