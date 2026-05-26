<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaidInvoiceJob;
use App\Models\Plugin;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Pricing;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Models\SslCertificate;
use App\Modules\Product\Services\SslService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SslManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_purchases_paid_certificate_and_payment_activates_it(): void
    {
        Queue::fake();
        config(['billing.ssl_certificate_price' => 120]);
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->post(route('client.ssl.store'), [
                'domain' => 'secure.example.com',
                'type' => 'paid',
                'years' => 2,
            ])
            ->assertRedirect();

        $certificate = SslCertificate::query()->where('domain', 'secure.example.com')->firstOrFail();
        $this->assertSame('Pending', $certificate->status);
        $this->assertDatabaseHas('invoice_items', [
            'type' => 'ssl_purchase',
            'rel_id' => $certificate->id,
            'amount' => 240,
        ]);

        $invoice = InvoiceItem::query()->where('type', 'ssl_purchase')->where('rel_id', $certificate->id)->firstOrFail()->invoice;
        $this->assertTrue(app(InvoiceService::class)->markAsPaid($invoice, 'manual', 'SSL-PAID-1'));
        Queue::assertPushed(ProcessPaidInvoiceJob::class, fn (ProcessPaidInvoiceJob $job) => $job->invoiceId === $invoice->id);

        app(SslService::class)->applyPaidInvoice($invoice->fresh(['items']));

        $certificate->refresh();
        $this->assertSame('Active', $certificate->status);
        $this->assertNotNull($certificate->certificate);
        $this->assertNotNull($certificate->private_key);
    }

    public function test_letsencrypt_certificate_is_issued_and_downloadable(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->post(route('client.ssl.store'), [
                'domain' => 'free.example.org',
                'type' => 'letsencrypt',
                'years' => 1,
            ])
            ->assertRedirect();

        $certificate = SslCertificate::query()->where('domain', 'free.example.org')->firstOrFail();
        $this->assertSame('Active', $certificate->status);
        $this->assertSame('letsencrypt', $certificate->type);
        $this->assertTrue($certificate->auto_renew);

        $this->actingAs($client, 'client')
            ->get(route('client.ssl.download', $certificate))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-pem-file')
            ->assertSee('BEGIN CERTIFICATE');
    }

    public function test_admin_can_filter_ssl_and_issue_letsencrypt_certificate(): void
    {
        $admin = $this->admin(['ssl.view', 'ssl.manage']);
        $client = $this->client();
        $paid = $this->certificate($client, 'paid-filter.example.com', 'paid', 'Active');
        $lets = $this->certificate($client, 'lets-filter.example.com', 'letsencrypt', 'Pending');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ssl.index', ['type' => 'letsencrypt']))
            ->assertOk()
            ->assertSee('lets-filter.example.com')
            ->assertDontSee('paid-filter.example.com');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ssl.issue', $lets))
            ->assertRedirect(route('admin.ssl.index'))
            ->assertSessionHas('status', '证书已签发');

        $this->assertSame('Active', $lets->fresh()->status);
        $this->assertSame('Active', $paid->fresh()->status);
    }

    public function test_ssl_commands_send_reminders_and_renew_letsencrypt(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $client = $this->client();
        $paid = $this->certificate($client, 'remind.example.net', 'paid', 'Active', now()->addDays(15));
        $lets = $this->certificate($client, 'renew.example.net', 'letsencrypt', 'Active', now()->addDays(20));

        $this->artisan('ssl:send-expiry-reminders')->assertExitCode(0);
        $this->assertDatabaseHas('email_logs', [
            'to' => $client->email,
            'template' => 'ssl_expiry_reminder',
        ]);

        $oldExpiry = $lets->expiry_date?->format('Y-m-d');
        $this->artisan('ssl:auto-renew-letsencrypt')->assertExitCode(0);
        $this->assertNotSame($oldExpiry, $lets->fresh()->expiry_date?->format('Y-m-d'));
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'ssl:auto-renew-letsencrypt',
            'status' => 'success',
        ]);
        $this->assertSame('Active', $paid->fresh()->status);
    }

    public function test_deploy_returns_failure_when_host_module_has_no_ssl_capability(): void
    {
        $this->installMockServer();
        $host = $this->host();
        $certificate = $this->certificate($host->client, 'deploy.example.com', 'letsencrypt', 'Active', now()->addDays(80), $host);
        $certificate->update([
            'certificate' => 'CERT',
            'private_key' => 'KEY',
            'ca_bundle' => 'CA',
        ]);

        $this->actingAs($host->client, 'client')
            ->post(route('client.ssl.deploy', $certificate))
            ->assertRedirect(route('client.ssl.show', $certificate))
            ->assertSessionHas('error', '当前主机模块不支持自动部署证书');
    }

    private function certificate(
        Client $client,
        string $domain,
        string $type,
        string $status,
        mixed $expiryDate = null,
        ?Host $host = null
    ): SslCertificate {
        return SslCertificate::query()->create([
            'client_id' => $client->id,
            'host_id' => $host?->id,
            'domain' => $domain,
            'type' => $type,
            'status' => $status,
            'issue_date' => now()->subMonth(),
            'expiry_date' => $expiryDate ?: now()->addYear(),
            'certificate' => 'CERT',
            'private_key' => 'KEY',
            'ca_bundle' => 'CA',
            'auto_renew' => $type === 'letsencrypt',
        ]);
    }

    private function host(): Host
    {
        $client = $this->client();
        $product = $this->product();
        $order = Order::query()->create([
            'client_id' => $client->id,
            'order_number' => 'ORD-SSL-' . random_int(1000, 9999),
            'status' => 'Paid',
            'amount' => 50,
            'currency_id' => $client->currency_id,
        ]);

        return Host::query()->create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'domain' => 'host.example.com',
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 50,
            'recurring_amount' => 50,
            'next_due_date' => now()->addMonth(),
            'next_invoice_date' => now()->addDays(23),
            'status' => 'Active',
            'auto_renew' => true,
        ])->load(['client', 'product']);
    }

    private function product(): Product
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => 'SSL 测试产品']);

        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => 'SSL VPS',
            'description' => 'SSL VPS 产品说明',
            'type' => 'vps',
            'auto_setup' => 'payment',
            'server_type' => 'mock_server',
            'hidden' => false,
            'retired' => false,
            'stock_control' => false,
        ]);

        Pricing::query()->create([
            'type' => 'product',
            'rel_id' => $product->id,
            'currency_id' => Currency::query()->where('code', 'CNY')->value('id') ?: 1,
            'monthly' => 50,
        ]);

        return $product;
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'ssl-client-' . random_int(1000, 9999),
            'email' => 'ssl-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function admin(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'ssl-admin-' . random_int(1000, 9999),
            'email' => 'ssl-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'ssl-admin', 'guard_name' => 'web']);
        $admin->syncRoles([$role]);
        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        Plugin::query()->where('name', 'smtp')->update(['config' => []]);
    }

    private function installMockServer(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('server', 'mock_server');
        $manager->enable('mock_server');
        Plugin::query()->where('name', 'mock_server')->update(['config' => []]);
    }
}
