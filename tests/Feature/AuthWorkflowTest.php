<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
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

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_client_workflows_use_separate_guards(): void
    {
        $this->seed();

        $this->post(route('admin.login.store'), [
            'username' => 'admin',
            'password' => 'admin123456',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated('admin');
        $this->assertGuest('client');

        $this->get(route('admin.products.create'))->assertOk();

        $product = $this->createDemoProduct();

        $currencyId = Currency::query()->where('code', 'CNY')->value('id');

        $this->post(route('admin.products.pricing.update', $product), [
            'currency_id' => $currencyId,
            'monthly' => 66,
            'monthly_setup' => 0,
            'quarterly' => 180,
            'quarterly_setup' => 0,
            'semiannually' => 330,
            'semiannually_setup' => 0,
            'annually' => 600,
            'annually_setup' => 0,
            'biennially' => 1100,
            'biennially_setup' => 0,
            'triennially' => 1500,
            'triennially_setup' => 0,
            'onetime' => -1,
            'hourly' => -1,
            'daily' => -1,
        ])->assertRedirect(route('admin.products.pricing', [$product, 'currency_id' => $currencyId]));

        $this->post(route('client.register.store'), [
            'username' => 'workflow-client',
            'email' => 'workflow-client@example.com',
            'password' => 'client123456',
            'password_confirmation' => 'client123456',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticated('client');
        $client = Client::query()->where('email', 'workflow-client@example.com')->firstOrFail();

        $this->post(route('client.cart.add'), [
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'qty' => 1,
        ])->assertRedirect(route('client.cart.index'));

        $this->post(route('client.cart.checkout'))->assertRedirect();
        $this->assertDatabaseHas('orders', ['client_id' => $client->id]);
        $this->assertDatabaseHas('invoices', ['client_id' => $client->id]);

        $ticket = $this->createClientTicket($client);

        $this->post(route('client.tickets.reply', $ticket), [
            'message' => '客户补充说明',
        ])->assertRedirect(route('client.tickets.show', $ticket));

        $this->post(route('admin.tickets.reply', $ticket), [
            'message' => '后台已处理',
        ])->assertRedirect(route('admin.tickets.show', $ticket));

        $this->post(route('admin.tickets.close', $ticket))
            ->assertRedirect(route('admin.tickets.show', $ticket));

        $this->assertDatabaseHas('ticket_replies', ['ticket_id' => $ticket->id, 'author_type' => 'admin']);
    }

    public function test_inactive_accounts_are_rejected_by_status_middleware(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'disabled-admin',
            'email' => 'disabled-admin@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 2,
        ]);

        $client = Client::query()->create([
            'username' => 'disabled-client',
            'email' => 'disabled-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 2,
        ]);

        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->actingAs($client, 'client')->get(route('client.dashboard'))->assertRedirect(route('client.login'));
    }

    public function test_client_status_middleware_rechecks_latest_database_status(): void
    {
        $client = Client::query()->create([
            'username' => 'stale-disabled-client',
            'email' => 'stale-disabled-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'company_name' => 'Before Disable',
        ]);
        $staleClient = $client->fresh();

        $client->update(['status' => 2]);

        $this->actingAs($staleClient, 'client')
            ->put(route('client.account.profile.update'), [
                'company_name' => 'Updated After Disable',
            ])
            ->assertRedirect(route('client.login'));

        $this->assertGuest('client');
        $this->assertSame('Before Disable', $client->fresh()->company_name);
    }

    public function test_auth_entrypoints_are_rate_limited(): void
    {
        $this->assertContains('throttle:10,1', \Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.login.store')->middleware());
        $this->assertContains('throttle:10,1', \Illuminate\Support\Facades\Route::getRoutes()->getByName('client.login.store')->middleware());
        $this->assertContains('throttle:5,1', \Illuminate\Support\Facades\Route::getRoutes()->getByName('client.register.store')->middleware());
    }

    public function test_admin_product_pricing_page_uses_selected_currency(): void
    {
        $this->seed();
        $this->actingAs(AdminUser::query()->where('username', 'admin')->firstOrFail(), 'admin');
        $product = $this->createDemoProduct();
        $cnyId = (int) Currency::query()->where('code', 'CNY')->value('id');
        $usd = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            ['prefix' => '$', 'suffix' => '', 'exchange_rate' => 7, 'is_default' => false]
        );
        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $usd->id,
            'monthly' => 9,
            'quarterly' => 25,
            'semiannually' => 48,
            'annually' => 90,
        ]);

        $this->get(route('admin.products.pricing', [$product, 'currency_id' => $usd->id]))
            ->assertOk()
            ->assertSee('value="' . $usd->id . '" selected', false)
            ->assertSee('value="9.00"', false)
            ->assertDontSee('value="66.00"', false);

        $this->post(route('admin.products.pricing.update', $product), [
            'currency_id' => $usd->id,
            'monthly' => 10,
            'monthly_setup' => 0,
            'quarterly' => 27,
            'quarterly_setup' => 0,
            'semiannually' => 52,
            'semiannually_setup' => 0,
            'annually' => 96,
            'annually_setup' => 0,
            'biennially' => -1,
            'biennially_setup' => 0,
            'triennially' => -1,
            'triennially_setup' => 0,
            'onetime' => -1,
            'hourly' => -1,
            'daily' => -1,
        ])->assertRedirect(route('admin.products.pricing', [$product, 'currency_id' => $usd->id]));

        $this->assertDatabaseHas('pricings', [
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $usd->id,
            'monthly' => 10,
        ]);
        $this->assertDatabaseHas('pricings', [
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $cnyId,
            'monthly' => 66,
        ]);
    }

    public function test_admin_product_pricing_page_ignores_array_currency_query_value(): void
    {
        $this->seed();
        $this->actingAs(AdminUser::query()->where('username', 'admin')->firstOrFail(), 'admin');
        $product = $this->createDemoProduct();
        $defaultCurrencyId = (int) Currency::query()->where('is_default', true)->value('id');

        $this->get(route('admin.products.pricing', [$product, 'currency_id' => [$defaultCurrencyId]]))
            ->assertOk()
            ->assertSee('value="' . $defaultCurrencyId . '" selected', false)
            ->assertSee('value="66.00"', false);
    }

    private function createDemoProduct(): Product
    {
        $group = ProductGroup::query()->firstOrCreate(
            ['name' => '测试产品组'],
            ['description' => '测试', 'sort_order' => 1, 'hidden' => false]
        );

        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => '测试云服务器',
            'description' => '用于认证流程测试',
            'type' => 'vps',
            'pay_type' => 'recurring',
            'pay_method' => 'prepaid',
            'auto_setup' => 'manual',
            'stock_control' => true,
            'stock_qty' => 10,
            'hidden' => false,
            'retired' => false,
            'is_featured' => true,
            'sort_order' => 1,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id'),
            'monthly' => 66,
            'quarterly' => 180,
            'semiannually' => 330,
            'annually' => 600,
        ]);

        return $product;
    }

    private function createClientTicket(Client $client): Ticket
    {
        $department = TicketDepartment::query()->firstOrCreate(['name' => '测试部门']);
        $status = TicketStatus::query()->firstOrCreate(
            ['name' => 'Open'],
            ['color' => '#16a34a', 'show_client' => true, 'is_default' => true, 'sort_order' => 1]
        );

        return Ticket::query()->create([
            'ticket_number' => 'TIC' . now()->format('YmdHis'),
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => '测试工单',
            'message' => '客户提交的问题',
            'priority' => 'Medium',
        ]);
    }
}
