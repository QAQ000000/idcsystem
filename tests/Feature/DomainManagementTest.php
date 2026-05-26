<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaidInvoiceJob;
use App\Models\EmailLog;
use App\Models\Plugin;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Product\Models\Domain;
use App\Modules\Product\Models\DomainPricing;
use App\Modules\Product\Services\DomainService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DomainManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_tld_pricing(): void
    {
        $admin = $this->admin(['domain.manage']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.domain-pricings.store'), [
                'tld' => 'com',
                'register_price' => 80,
                'renew_price' => 90,
                'transfer_price' => 70,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.domain-pricings.index'))
            ->assertSessionHas('status', 'TLD 价格已创建');

        $pricing = DomainPricing::query()->where('tld', '.com')->firstOrFail();
        $this->assertSame('80.00', $pricing->register_price);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.domain-pricings.update', $pricing), [
                'tld' => '.com',
                'register_price' => 88,
                'renew_price' => 99,
                'transfer_price' => 77,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.domain-pricings.index'))
            ->assertSessionHas('status', 'TLD 价格已保存');

        $this->assertSame('99.00', $pricing->fresh()->renew_price);
    }

    public function test_client_registers_domain_and_paid_invoice_activates_it(): void
    {
        Queue::fake();
        $client = $this->client();
        DomainPricing::query()->create([
            'tld' => '.com',
            'register_price' => 80,
            'renew_price' => 90,
            'transfer_price' => 70,
            'active' => true,
        ]);

        $this->actingAs($client, 'client')
            ->get(route('client.domains.register', ['domain' => 'example.com']))
            ->assertOk()
            ->assertSee('域名可注册');

        $this->actingAs($client, 'client')
            ->post(route('client.domains.store'), [
                'domain' => 'example.com',
                'years' => 2,
                'whois_privacy' => '1',
            ])
            ->assertRedirect();

        $domain = Domain::query()->where('domain', 'example.com')->firstOrFail();
        $this->assertSame('Pending', $domain->status);
        $this->assertTrue($domain->whois_privacy);
        $this->assertDatabaseHas('invoice_items', [
            'type' => 'domain_register',
            'rel_id' => $domain->id,
            'amount' => 160,
        ]);

        $invoice = InvoiceItem::query()->where('type', 'domain_register')->where('rel_id', $domain->id)->firstOrFail()->invoice;
        $this->assertTrue(app(InvoiceService::class)->markAsPaid($invoice, 'manual', 'DOMAIN-REGISTER-1'));
        Queue::assertPushed(ProcessPaidInvoiceJob::class, fn (ProcessPaidInvoiceJob $job) => $job->invoiceId === $invoice->id);

        app(DomainService::class)->applyPaidInvoice($invoice->fresh(['items']));

        $this->assertSame('Active', $domain->fresh()->status);
    }

    public function test_client_can_update_nameservers_and_create_renewal_invoice(): void
    {
        $client = $this->client();
        DomainPricing::query()->create([
            'tld' => '.net',
            'register_price' => 60,
            'renew_price' => 66,
            'transfer_price' => 50,
            'active' => true,
        ]);
        $domain = Domain::query()->create([
            'client_id' => $client->id,
            'domain' => 'renew-me.net',
            'tld' => '.net',
            'status' => 'Active',
            'registration_date' => now()->subYear(),
            'expiry_date' => now()->addMonth(),
            'auto_renew' => true,
            'whois_privacy' => false,
            'nameservers' => [],
            'registrar' => 'manual',
        ]);

        $this->actingAs($client, 'client')
            ->post(route('client.domains.nameservers', $domain), [
                'nameservers' => ['NS1.EXAMPLE.NET', 'ns2.example.net'],
            ])
            ->assertRedirect(route('client.domains.show', $domain))
            ->assertSessionHas('status', 'DNS 服务器已更新');

        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $domain->fresh()->nameservers);

        $this->actingAs($client, 'client')
            ->post(route('client.domains.renew', $domain), ['years' => 1])
            ->assertRedirect();

        $this->assertDatabaseHas('invoice_items', [
            'type' => 'domain_renew',
            'rel_id' => $domain->id,
            'amount' => 66,
        ]);
    }

    public function test_admin_domain_list_filters_by_status(): void
    {
        $admin = $this->admin(['domain.view']);
        $client = $this->client();
        $this->domain($client, 'active-filter.com', 'Active');
        $this->domain($client, 'pending-filter.com', 'Pending');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.domains.index', ['status' => 'Active']))
            ->assertOk()
            ->assertSee('active-filter.com')
            ->assertDontSee('pending-filter.com');
    }

    public function test_domain_expiry_reminder_and_auto_renew_commands_write_task_logs(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $client = $this->client();
        DomainPricing::query()->create([
            'tld' => '.org',
            'register_price' => 50,
            'renew_price' => 55,
            'transfer_price' => 45,
            'active' => true,
        ]);
        $domain = $this->domain($client, 'notify-renew.org', 'Active', now()->addDays(7));

        $this->artisan('domains:send-expiry-reminders')->assertExitCode(0);
        $this->assertDatabaseHas('email_logs', [
            'to' => $client->email,
            'template' => 'domain_expiry_reminder',
        ]);
        $this->assertSame(1, EmailLog::query()->where('template', 'domain_expiry_reminder')->count());

        $this->artisan('domains:auto-renew')->assertExitCode(0);
        $this->assertDatabaseHas('invoice_items', [
            'type' => 'domain_renew',
            'rel_id' => $domain->id,
            'amount' => 55,
        ]);
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'domains:auto-renew',
            'status' => 'success',
        ]);

        $this->artisan('domains:auto-renew')->assertExitCode(0);
        $this->assertSame(1, InvoiceItem::query()->where('type', 'domain_renew')->where('rel_id', $domain->id)->count());
    }

    private function domain(Client $client, string $domain, string $status, mixed $expiryDate = null): Domain
    {
        $parts = explode('.', $domain);

        return Domain::query()->create([
            'client_id' => $client->id,
            'domain' => $domain,
            'tld' => '.' . end($parts),
            'status' => $status,
            'registration_date' => now()->subYear(),
            'expiry_date' => $expiryDate ?: now()->addYear(),
            'auto_renew' => true,
            'whois_privacy' => false,
            'nameservers' => [],
            'registrar' => 'manual',
        ]);
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'domain-client-' . random_int(1000, 9999),
            'email' => 'domain-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }

    private function admin(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'domain-admin-' . random_int(1000, 9999),
            'email' => 'domain-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'domain-admin', 'guard_name' => 'web']);
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
}
