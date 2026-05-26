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
use App\Models\Plugin;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
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
            'privacy_policy' => '1',
        ])->assertRedirect(route('verification.notice'));

        $this->assertAuthenticated('client');
        $client = Client::query()->where('email', 'workflow-client@example.com')->firstOrFail();
        $this->assertFalse($client->isActive());
        $this->assertNull($client->email_verified_at);
        $this->assertDatabaseHas('email_logs', [
            'to' => 'workflow-client@example.com',
            'template' => 'email_verification',
        ]);

        $this->get(route('client.dashboard'))->assertRedirect(route('client.login'));
        $this->assertGuest('client');

        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addDay(),
            [
                'id' => $client->id,
                'hash' => sha1((string) $client->email),
            ]
        );
        $this->get($verifyUrl)->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticated('client');
        $this->assertTrue($client->fresh()->isActive());
        $this->assertNotNull($client->fresh()->email_verified_at);

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

    public function test_unverified_client_can_resend_verification_email(): void
    {
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $client = Client::query()->create([
            'username' => 'resend-client',
            'email' => 'resend-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 0,
        ]);

        $this->actingAs($client, 'client')
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('验证邮箱');

        $this->actingAs($client, 'client')
            ->post(route('verification.resend'))
            ->assertRedirect()
            ->assertSessionHas('status', '验证邮件已重新发送。');

        $this->assertDatabaseHas('email_logs', [
            'to' => 'resend-client@example.com',
            'template' => 'email_verification',
        ]);
    }

    public function test_email_verification_rejects_invalid_hash(): void
    {
        $client = Client::query()->create([
            'username' => 'bad-hash-client',
            'email' => 'bad-hash-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 0,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addDay(),
            [
                'id' => $client->id,
                'hash' => 'invalid-hash',
            ]
        );

        $this->get($url)->assertForbidden();
        $this->assertFalse($client->fresh()->isActive());
        $this->assertNull($client->fresh()->email_verified_at);
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

    public function test_wechat_oauth_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('oauth'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'wechat_oauth'));
        $this->assertTrue($manager->install('oauth', 'wechat_oauth'));
        $this->assertDatabaseHas('plugins', ['name' => 'wechat_oauth', 'type' => 'oauth', 'status' => 0]);
        $this->assertTrue($manager->enable('wechat_oauth'));
        $this->assertDatabaseHas('plugins', ['name' => 'wechat_oauth', 'status' => 1]);
    }

    public function test_login_page_shows_wechat_oauth_entry(): void
    {
        $this->get(route('client.login'))
            ->assertOk()
            ->assertSee(route('oauth.wechat.redirect'), false)
            ->assertSee('微信登录');
    }

    public function test_wechat_oauth_callback_creates_client_and_logs_in(): void
    {
        $this->installWechatOauth([
            'mock_user' => json_encode([
                'openid' => 'wechat-openid-001',
                'unionid' => 'wechat-unionid-001',
                'nickname' => 'Wechat User',
                'email' => 'wechat-user@example.com',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->withSession(['oauth_wechat_state' => 'state-ok'])
            ->get(route('oauth.wechat.callback', ['code' => 'mock-code', 'state' => 'state-ok']))
            ->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticated('client');
        $this->assertDatabaseHas('clients', [
            'email' => 'wechat-user@example.com',
            'status' => 1,
        ]);
        $clientId = Client::query()->where('email', 'wechat-user@example.com')->value('id');
        $this->assertDatabaseHas('client_oauth', [
            'client_id' => $clientId,
            'provider' => 'wechat',
            'provider_user_id' => 'wechat-openid-001',
        ]);
    }

    public function test_wechat_oauth_callback_rejects_invalid_state(): void
    {
        $this->installWechatOauth();

        $this->withSession(['oauth_wechat_state' => 'state-ok'])
            ->get(route('oauth.wechat.callback', ['code' => 'mock-code', 'state' => 'wrong']))
            ->assertRedirect(route('client.login'));

        $this->assertGuest('client');
        $this->assertDatabaseMissing('client_oauth', ['provider' => 'wechat']);
    }

    public function test_image_captcha_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('captcha'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'image_captcha'));
        $this->assertTrue($manager->install('captcha', 'image_captcha'));
        $this->assertDatabaseHas('plugins', ['name' => 'image_captcha', 'type' => 'captcha', 'status' => 0]);
        $this->assertTrue($manager->enable('image_captcha'));
        $this->assertDatabaseHas('plugins', ['name' => 'image_captcha', 'status' => 1]);
    }

    public function test_enabled_captcha_blocks_login_with_invalid_code(): void
    {
        $this->installImageCaptcha();
        app(\App\Services\SettingsService::class)->set('captcha_enabled', true, 'general');

        Client::query()->create([
            'username' => 'captcha-login',
            'email' => 'captcha-login@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        $this->get(route('client.login'))
            ->assertOk()
            ->assertSee('captcha_key', false)
            ->assertSee('/captcha/image/', false);

        $this->post(route('client.login.store'), [
            'email' => 'captcha-login@example.com',
            'password' => 'client123456',
            'captcha_key' => 'missing-key',
            'captcha_code' => 'WRONG',
        ])->assertSessionHasErrors('captcha_code');

        $this->assertGuest('client');
    }

    public function test_enabled_captcha_blocks_register_with_invalid_code(): void
    {
        $this->installImageCaptcha();
        app(\App\Services\SettingsService::class)->set('captcha_enabled', true, 'general');

        $this->post(route('client.register.store'), [
            'username' => 'captcha-register',
            'email' => 'captcha-register@example.com',
            'password' => 'client123456',
            'password_confirmation' => 'client123456',
            'privacy_policy' => '1',
            'captcha_key' => 'missing-key',
            'captcha_code' => 'WRONG',
        ])->assertSessionHasErrors('captcha_code');

        $this->assertDatabaseMissing('clients', ['email' => 'captcha-register@example.com']);
    }

    public function test_image_captcha_route_returns_png(): void
    {
        $this->installImageCaptcha();

        $this->get(route('captcha.image', ['key' => str_repeat('a', 40)]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
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

    public function test_admin_product_pricing_rejects_amount_above_database_capacity(): void
    {
        $this->seed();
        $this->actingAs(AdminUser::query()->where('username', 'admin')->firstOrFail(), 'admin');
        $product = $this->createDemoProduct();
        $currencyId = (int) Currency::query()->where('code', 'CNY')->value('id');

        $this->post(route('admin.products.pricing.update', $product), [
            'currency_id' => $currencyId,
            'monthly' => 100000000,
            'monthly_setup' => 0,
            'quarterly' => -1,
            'quarterly_setup' => 0,
            'semiannually' => -1,
            'semiannually_setup' => 0,
            'annually' => -1,
            'annually_setup' => 0,
            'biennially' => -1,
            'biennially_setup' => 0,
            'triennially' => -1,
            'triennially_setup' => 0,
            'onetime' => -1,
            'hourly' => -1,
            'daily' => -1,
        ])->assertSessionHasErrors('monthly');

        $this->assertDatabaseMissing('pricings', [
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => $currencyId,
            'monthly' => 100000000,
        ]);
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

    private function installWechatOauth(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('oauth', 'wechat_oauth');
        $manager->enable('wechat_oauth');
        Plugin::query()->where('name', 'wechat_oauth')->update([
            'config' => $config + [
                'app_id' => 'wx-oauth-app',
                'app_secret' => 'secret',
                'scope' => 'snsapi_login',
                'redirect_url' => route('oauth.wechat.callback'),
                'mock_user' => json_encode([
                    'openid' => 'wechat-openid-default',
                    'nickname' => 'Wechat User',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);
    }

    private function installImageCaptcha(array $config = []): void
    {
        $manager = app(PluginManager::class);
        $manager->install('captcha', 'image_captcha');
        $manager->enable('image_captcha');
        Plugin::query()->where('name', 'image_captcha')->update([
            'config' => $config + [
                'length' => 5,
                'ttl' => 300,
            ],
        ]);
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
