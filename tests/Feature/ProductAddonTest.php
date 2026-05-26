<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaidInvoiceJob;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\CartService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductAddon;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductAddonTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_product_addons(): void
    {
        $admin = $this->admin();
        $product = $this->product('Addon Admin VPS', 50);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.products.addons.index', $product))
            ->assertOk()
            ->assertSee('新建附加项');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.products.addons.store', $product), [
                'name' => '备份服务',
                'description' => '每日快照',
                'billing_cycle' => 'recurring',
                'price' => 12.5,
                'stock_qty' => 3,
                'sort_order' => 8,
                'active' => 1,
            ])
            ->assertRedirect(route('admin.products.addons.index', $product))
            ->assertSessionHas('status', '附加项已创建');

        $addon = ProductAddon::query()->where('name', '备份服务')->firstOrFail();
        $this->assertSame('12.50', (string) $addon->price);
        $this->assertSame(3, $addon->stock_qty);
        $this->assertDatabaseHas('admin_action_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'product_addon.create',
            'target_id' => $addon->id,
            'result' => 'success',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.products.addons.update', [$product, $addon]), [
                'name' => '高级备份',
                'billing_cycle' => 'one_time',
                'price' => 20,
                'stock_qty' => 0,
                'sort_order' => 10,
                'active' => 0,
            ])
            ->assertRedirect(route('admin.products.addons.index', $product));

        $this->assertSame('高级备份', $addon->fresh()->name);
        $this->assertFalse($addon->fresh()->active);

        $otherProduct = $this->product('Other Addon Product', 50);
        $this->actingAs($admin, 'admin')
            ->put(route('admin.products.addons.update', [$otherProduct, $addon]), [
                'name' => '错误归属',
                'billing_cycle' => 'recurring',
                'price' => 1,
            ])
            ->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.products.addons.destroy', [$product, $addon]))
            ->assertRedirect(route('admin.products.addons.index', $product))
            ->assertSessionHas('status', '附加项已删除');

        $this->assertDatabaseMissing('product_addons', ['id' => $addon->id]);
    }

    public function test_product_detail_cart_and_checkout_support_addons(): void
    {
        Mail::fake();
        $client = $this->client();
        $product = $this->product('Addon Checkout VPS', 50);
        $addon = $this->addon($product, ['name' => '独立 IP', 'price' => 15, 'stock_qty' => 2]);

        $this->get(route('client.products.show', $product))
            ->assertOk()
            ->assertSee('独立 IP');

        $this->actingAs($client, 'client')
            ->post(route('client.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'addons' => [$addon->id],
            ])
            ->assertRedirect(route('client.cart.index'));

        $cart = app(CartService::class)->getCart($client);
        $this->assertSame(65.0, $cart['totals']['subtotal']);
        $this->assertSame(65.0, $cart['totals']['total']);
        $this->assertSame(15.0, $cart['items'][0]['addon_total']);

        $this->actingAs($client, 'client')
            ->get(route('client.cart.index'))
            ->assertOk()
            ->assertSee('附加项')
            ->assertSee('独立 IP');

        $order = app(CartService::class)->checkout($client);
        $this->assertSame('65.00', (string) $order->amount);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $order->invoice_id,
            'type' => 'addon',
            'description' => '附加项：独立 IP',
            'amount' => '15.00',
            'rel_id' => $addon->id,
        ]);

        $host = $order->hosts()->firstOrFail();
        $this->assertDatabaseHas('host_addons', [
            'host_id' => $host->id,
            'addon_id' => $addon->id,
            'price' => '15.00',
            'billing_cycle' => 'recurring',
            'status' => 'Active',
        ]);

        $this->actingAs($client, 'client')
            ->get(route('client.hosts.show', $host))
            ->assertOk()
            ->assertSee('独立 IP');
    }

    public function test_sold_out_or_foreign_addons_cannot_be_added_to_cart(): void
    {
        $client = $this->client();
        $product = $this->product('Addon Stock VPS', 50);
        $soldOut = $this->addon($product, ['name' => '售罄备份', 'stock_qty' => 0]);
        $foreign = $this->addon($this->product('Foreign Addon VPS', 50), ['name' => '其他产品附加项']);

        $this->get(route('client.products.show', $product))
            ->assertOk()
            ->assertDontSee('售罄备份');

        $this->actingAs($client, 'client')
            ->post(route('client.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'addons' => [$soldOut->id],
            ])
            ->assertRedirect(route('client.products.show', $product))
            ->assertSessionHasErrors('addons');

        $this->actingAs($client, 'client')
            ->post(route('client.cart.add'), [
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'addons' => [$foreign->id],
            ])
            ->assertRedirect(route('client.products.show', $product))
            ->assertSessionHasErrors('addons');

        $this->assertSame([], app(CartService::class)->getCart($client)['items']);
    }

    public function test_client_can_buy_addon_from_host_detail_after_payment(): void
    {
        Mail::fake();
        $host = $this->host();
        $addon = $this->addon($host->product, ['name' => '灾备服务', 'price' => 18]);

        $this->actingAs($host->client, 'client')
            ->get(route('client.hosts.show', $host))
            ->assertOk()
            ->assertSee('添加附加项')
            ->assertSee('灾备服务');

        $response = $this->actingAs($host->client, 'client')
            ->post(route('client.hosts.addons.store', $host), ['addon_id' => $addon->id]);

        $invoice = InvoiceItem::query()->where('type', 'addon')->where('rel_id', $addon->id)->firstOrFail()->invoice;
        $response->assertRedirect(route('client.invoices.show', $invoice));
        $this->assertDatabaseMissing('host_addons', [
            'host_id' => $host->id,
            'addon_id' => $addon->id,
        ]);

        $this->assertTrue(app(InvoiceService::class)->markAsPaid($invoice, 'manual', 'ADDON-ATTACH-1'));
        (new ProcessPaidInvoiceJob($invoice->id))->handle(
            app(\App\Modules\Order\Services\HostService::class),
            app(\App\Modules\Product\Services\DomainService::class),
            app(\App\Modules\Product\Services\SslService::class),
            app(\App\Services\NotificationService::class),
            app(\App\Modules\User\Services\AffiliateService::class)
        );

        $this->assertDatabaseHas('host_addons', [
            'host_id' => $host->id,
            'addon_id' => $addon->id,
            'status' => 'Active',
        ]);
    }

    public function test_billing_generates_recurring_addon_invoice_once(): void
    {
        Mail::fake();
        $host = $this->host(['next_due_date' => now()->addDays(2)]);
        $addon = $this->addon($host->product, ['name' => '周期备份', 'price' => 9]);
        $hostAddon = app(\App\Modules\Product\Services\AddonService::class)->attach($host, $addon);
        $hostAddon->update(['next_due_date' => now()->addDays(2)]);

        $this->assertSame(1, app(BillingService::class)->generateRecurringInvoices());
        $this->assertSame(1, InvoiceItem::query()
            ->where('type', 'addon')
            ->where('rel_id', $hostAddon->id)
            ->whereHas('invoice', fn ($query) => $query->where('status', 'Unpaid'))
            ->count());

        $this->assertSame(0, app(BillingService::class)->generateRecurringInvoices());
    }

    public function test_inactive_client_is_skipped_for_recurring_addon_invoice(): void
    {
        $host = $this->host(['next_invoice_date' => null, 'next_due_date' => now()->addDays(2)]);
        $host->client->update(['status' => 2]);
        $addon = $this->addon($host->product, ['name' => '失效客户备份', 'price' => 9]);
        $hostAddon = app(\App\Modules\Product\Services\AddonService::class)->attach($host, $addon);
        $hostAddon->update(['next_due_date' => now()->addDays(2)]);

        $this->assertSame(0, app(BillingService::class)->generateRecurringInvoices());
        $this->assertDatabaseMissing('invoice_items', [
            'type' => 'addon',
            'rel_id' => $hostAddon->id,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'addon-admin-' . random_int(1000, 9999),
            'email' => 'addon-admin-' . random_int(1000, 9999) . '@example.com',
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
            'username' => 'addon-client-' . random_int(1000, 9999),
            'email' => 'addon-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function product(string $name, float $monthly): Product
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );
        $group = ProductGroup::query()->firstOrCreate(['name' => '附加项产品']);
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

    private function addon(Product $product, array $overrides = []): ProductAddon
    {
        return ProductAddon::query()->create(array_merge([
            'product_id' => $product->id,
            'name' => 'Addon ' . random_int(1000, 9999),
            'description' => '附加项说明',
            'billing_cycle' => 'recurring',
            'price' => 10,
            'stock_qty' => null,
            'active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    private function host(array $overrides = []): Host
    {
        $client = $this->client();
        $product = $this->product('Host Addon VPS', 50);
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-ADDON-' . random_int(1000, 9999),
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
            'next_invoice_date' => null,
            'status' => 'Active',
            'auto_renew' => true,
        ], $overrides))->load(['client', 'product']);
    }
}
