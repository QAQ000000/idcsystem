<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\HostService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_webhook_and_send_test_delivery(): void
    {
        Http::fake(['https://example.com/hook' => Http::response('ok', 200)]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.webhooks.store'), [
                'name' => 'ERP',
                'url' => 'https://example.com/hook',
                'events' => ['order.created', 'invoice.paid'],
                'secret' => 'secret-value',
                'active' => '1',
            ])
            ->assertRedirect(route('admin.webhooks.index'))
            ->assertSessionHas('status', 'Webhook 已创建');

        $webhook = Webhook::query()->where('name', 'ERP')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.webhooks.test', $webhook))
            ->assertRedirect(route('admin.webhooks.deliveries', $webhook))
            ->assertSessionHas('status', '测试 Webhook 已发送');

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'event' => 'webhook.test',
            'status' => 'success',
            'status_code' => 200,
        ]);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Webhook-Signature')
            && $request->header('X-Webhook-Event')[0] === 'webhook.test');
    }

    public function test_webhook_service_dispatches_matching_active_webhooks_only(): void
    {
        Bus::fake();
        $active = $this->webhook(['events' => ['order.created']]);
        $this->webhook(['events' => ['invoice.paid']]);
        $this->webhook(['events' => ['order.created'], 'active' => false]);

        app(WebhookService::class)->dispatch('order.created', ['order_id' => 123]);

        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $active->id,
            'event' => 'order.created',
            'status' => 'pending',
        ]);
        Bus::assertDispatched(DeliverWebhookJob::class);
    }

    public function test_webhook_delivery_sends_signed_json_and_records_failure(): void
    {
        Http::fake(['https://example.com/hook' => Http::response('bad', 500)]);
        $webhook = $this->webhook(['secret' => 'top-secret']);
        $delivery = $webhook->deliveries()->create([
            'event' => 'invoice.paid',
            'payload' => ['invoice_id' => 10],
            'status' => 'pending',
        ]);

        $this->assertFalse(app(WebhookService::class)->deliver($delivery));

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(500, $delivery->status_code);
        Http::assertSent(function ($request): bool {
            $body = $request->body();
            $signature = 'sha256=' . hash_hmac('sha256', $body, 'top-secret');

            return $request->url() === 'https://example.com/hook'
                && $request->header('X-Webhook-Signature')[0] === $signature;
        });
    }

    public function test_order_and_invoice_events_create_webhook_deliveries(): void
    {
        Bus::fake();
        $this->webhook(['events' => ['order.created', 'invoice.paid']]);
        $client = $this->client();
        $product = $this->product();

        $order = app(OrderService::class)->create($client, [[
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'currency_id' => $client->currency_id,
        ]]);
        app(InvoiceService::class)->markAsPaid($order->invoice, 'manual', 'WEBHOOK-PAID-1');

        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'order.created']);
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'invoice.paid']);
    }

    public function test_host_status_events_create_webhook_deliveries(): void
    {
        Bus::fake();
        $this->webhook(['events' => ['host.suspended', 'host.unsuspended', 'host.terminated']]);
        $host = $this->host(['status' => 'Active']);
        $service = app(HostService::class);

        $this->assertTrue($service->suspend($host, 'overdue'));
        $this->assertTrue($service->unsuspend($host->fresh()));
        $this->assertTrue($service->terminate($host->fresh()));

        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'host.suspended']);
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'host.unsuspended']);
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'host.terminated']);
    }

    private function webhook(array $overrides = []): Webhook
    {
        return Webhook::query()->create(array_merge([
            'name' => 'Webhook ' . random_int(1000, 9999),
            'url' => 'https://example.com/hook',
            'events' => ['order.created'],
            'secret' => 'secret-' . random_int(1000, 9999),
            'active' => true,
        ], $overrides));
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'webhook-client-' . random_int(1000, 9999),
            'email' => 'webhook-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(): Product
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $group = ProductGroup::query()->firstOrCreate(['name' => 'Webhook 产品组']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'Webhook VPS ' . random_int(1000, 9999),
            'type' => 'vps',
            'auto_setup' => 'manual',
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

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $product = $this->product();
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-WEBHOOK-' . random_int(1000, 9999),
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

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'webhook-admin-' . random_int(1000, 9999),
            'email' => 'webhook-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
