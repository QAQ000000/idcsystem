<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Services\CartService;
use App\Modules\Order\Services\HostService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Services\PricingService;
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
}
