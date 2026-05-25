<?php

namespace Tests\Feature;

use App\Models\ClientLoginLog;
use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\User\Services\ClientService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_view_and_update_profile(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->get(route('client.account.profile'))
            ->assertOk();

        $this->actingAs($client, 'client')
            ->put(route('client.account.profile.update'), [
                'username' => 'client-updated',
                'company_name' => 'IDC Co',
                'phone_code' => '86',
                'phone' => '13800138011',
                'country' => '中国',
                'province' => '广东',
                'city' => '深圳',
                'address' => '科技园',
            ])
            ->assertRedirect(route('client.account.profile'));

        $client->refresh();
        $this->assertSame('client-security', $client->username);
        $this->assertSame('IDC Co', $client->company_name);
        $this->assertSame('13800138011', $client->phone);
        $this->assertSame('科技园', $client->address);
    }

    public function test_client_cannot_change_password_with_wrong_current_password(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'client')
            ->put(route('client.account.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_client_password_change_rechecks_latest_password_hash(): void
    {
        $client = $this->client();
        $staleClient = $client->fresh();
        $client->update(['password' => Hash::make('changed-elsewhere123')]);

        $this->actingAs($staleClient, 'client')
            ->put(route('client.account.password.update'), [
                'current_password' => 'client123456',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('changed-elsewhere123', $client->fresh()->password));
        $this->assertFalse(Hash::check('newpassword123', $client->fresh()->password));
    }

    public function test_client_can_change_password_and_receive_notification(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSmtp();
        $this->installSms();
        $client = $this->client();
        app(SettingsService::class)->set('notify_password_changed_mail', true, 'notification');
        app(SettingsService::class)->set('notify_password_changed_sms', true, 'notification');

        $this->actingAs($client, 'client')
            ->put(route('client.account.password.update'), [
                'current_password' => 'client123456',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertRedirect(route('client.account.security'));

        $this->assertTrue(Hash::check('newpassword123', $client->fresh()->password));
        $this->assertDatabaseHas('email_logs', ['template' => 'password_changed']);
        $this->assertDatabaseHas('sms_logs', ['template' => 'password_changed']);
    }

    public function test_client_login_creates_login_logs(): void
    {
        $client = $this->client();
        $this->post(route('client.login.store'), [
            'email' => $client->email,
            'password' => 'client123456',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertDatabaseHas('client_login_logs', ['client_id' => $client->id]);
    }

    public function test_admin_client_show_page_displays_enhanced_information(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $log = ClientLoginLog::query()->create([
            'client_id' => $client->id,
            'ip' => '127.0.0.1',
            'user_agent' => 'Test Agent token:client-token authorization=client-auth cookie:client-cookie session=client-session bearer=client-bearer password=client-password signature=client-sign',
            'logged_in_at' => now(),
        ]);

        $log->refresh();
        $this->assertSame(
            'Test Agent token:[FILTERED] authorization=[FILTERED] cookie:[FILTERED] session=[FILTERED] bearer=[FILTERED] password=[FILTERED] signature=[FILTERED]',
            $log->user_agent
        );

        $this->actingAs($admin, 'admin')
            ->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertSee('token:[FILTERED]')
            ->assertDontSee('client-token')
            ->assertDontSee('client-auth')
            ->assertDontSee('client-cookie')
            ->assertDontSee('client-session')
            ->assertDontSee('client-bearer')
            ->assertDontSee('client-password')
            ->assertDontSee('client-sign');
    }

    public function test_client_view_only_admin_cannot_see_unpermitted_related_business_summaries(): void
    {
        $admin = AdminUser::query()->create([
            'username' => 'client-view-only-' . random_int(1000, 9999),
            'email' => 'client-view-only-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        Permission::query()->firstOrCreate(['name' => 'client.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->givePermissionTo('client.view');

        $client = $this->client();
        $this->createOrder($client, 'ORD-CLIENT-PRIVATE');
        $this->createInvoice($client, 'INV-CLIENT-PRIVATE');
        $this->createTicket($client, 'TICCLIENTPRIVATE', '客户私有工单');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertDontSee('查看账单')
            ->assertDontSee('查看订单')
            ->assertDontSee('查看工单')
            ->assertDontSee('ORD-CLIENT-PRIVATE')
            ->assertDontSee('INV-CLIENT-PRIVATE')
            ->assertDontSee('TICCLIENTPRIVATE')
            ->assertDontSee('客户私有工单');
    }

    public function test_credit_adjustments_reject_non_positive_amounts(): void
    {
        $client = $this->client();
        $client->update(['credit' => 100]);
        $service = app(ClientService::class);

        $this->assertFalse($service->addCredit($client, 0, 'zero add'));
        $this->assertFalse($service->addCredit($client, -10, 'negative add'));
        $this->assertFalse($service->deductCredit($client, 0, 'zero deduct'));
        $this->assertFalse($service->deductCredit($client, -10, 'negative deduct'));

        $this->assertSame('100.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
    }

    public function test_credit_adjustments_reject_amounts_larger_than_credit_log_capacity(): void
    {
        $client = $this->client();
        $client->update(['credit' => 100]);
        $service = app(ClientService::class);

        $this->assertFalse($service->addCredit($client, 100000000, 'too large add'));
        $this->assertFalse($service->deductCredit($client, 100000000, 'too large deduct'));

        $this->assertSame('100.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
    }

    public function test_credit_adjustments_reject_inactive_clients(): void
    {
        $client = $this->client();
        $client->update(['credit' => 100, 'status' => 2]);
        $service = app(ClientService::class);

        $this->assertFalse($service->addCredit($client->fresh(), 50, 'inactive add'));
        $this->assertFalse($service->deductCredit($client->fresh(), 50, 'inactive deduct'));
        $this->assertSame('100.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
    }

    public function test_credit_adjustments_reject_deleted_clients(): void
    {
        $client = $this->client();
        $client->update(['credit' => 100]);
        $client->delete();
        $service = app(ClientService::class);

        $this->assertFalse($service->addCredit($client->fresh(), 50, 'deleted add'));
        $this->assertFalse($service->deductCredit($client->fresh(), 50, 'deleted deduct'));
        $this->assertSame('100.00', (string) Client::withTrashed()->findOrFail($client->id)->credit);
        $this->assertDatabaseCount('credits', 0);
    }

    public function test_credit_deduction_uses_locked_fresh_balance(): void
    {
        $client = $this->client();
        $client->update(['credit' => 100]);
        $staleClient = $client->fresh();
        $client->update(['credit' => 20]);

        $this->assertFalse(app(ClientService::class)->deductCredit($staleClient, 50, 'stale balance deduct'));
        $this->assertSame('20.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
    }

    public function test_credit_addition_uses_locked_fresh_client_status(): void
    {
        $client = $this->client();
        $staleClient = $client->fresh();
        $client->update(['status' => 2, 'credit' => 100]);

        $this->assertFalse(app(ClientService::class)->addCredit($staleClient, 50, 'stale status add'));
        $this->assertSame('100.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
    }

    public function test_admin_client_update_rejects_invalid_status_values(): void
    {
        $admin = $this->admin();
        $client = $this->client();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.clients.update', $client), [
                'status' => 999,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(1, $client->fresh()->status);
    }

    public function test_admin_can_clear_optional_client_phone_without_clearing_password(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $oldPassword = $client->password;
        $client->update(['phone' => '13800138010']);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.clients.update', $client), [
                'username' => $client->username,
                'email' => $client->email,
                'password' => '',
                'phone' => '',
                'status' => 1,
            ])
            ->assertRedirect(route('admin.clients.show', $client));

        $client->refresh();
        $this->assertNull($client->phone);
        $this->assertSame($oldPassword, $client->password);
    }

    public function test_admin_cannot_add_credit_to_inactive_client(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $client->update(['status' => 2, 'credit' => 100]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.add-credit', $client), [
                'amount' => 50,
                'description' => '停用客户充值',
            ])
            ->assertRedirect(route('admin.clients.show', $client))
            ->assertSessionHas('error', '客户状态不允许充值');

        $this->assertSame('100.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'client.add_credit',
            'target_id' => $client->id,
            'result' => 'failed',
            'error' => '客户状态不允许充值',
        ]);
    }

    public function test_admin_add_credit_rejects_amount_larger_than_credit_log_capacity(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $client->update(['credit' => 100]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.clients.show', $client))
            ->post(route('admin.clients.add-credit', $client), [
                'amount' => 100000000,
                'description' => '超额充值',
            ])
            ->assertRedirect(route('admin.clients.show', $client))
            ->assertSessionHasErrors('amount');

        $this->assertSame('100.00', (string) $client->fresh()->credit);
        $this->assertDatabaseCount('credits', 0);
        $this->assertDatabaseMissing('admin_action_logs', [
            'action' => 'client.add_credit',
            'target_id' => $client->id,
        ]);
    }

    public function test_admin_related_lists_can_filter_by_client_keyword(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $other = $this->client('other-client', 'other-client@example.com');
        $this->createOrder($client, 'ORD-CLIENT-ONE');
        $this->createOrder($other, 'ORD-CLIENT-TWO');
        $this->createInvoice($client, 'INV-CLIENT-ONE');
        $this->createInvoice($other, 'INV-CLIENT-TWO');
        $this->createTicket($client, 'TICCLIENTONE', '目标客户工单');
        $this->createTicket($other, 'TICCLIENTTWO', '其他客户工单');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.index', ['keyword' => $client->username]))
            ->assertOk()
            ->assertSee('ORD-CLIENT-ONE')
            ->assertDontSee('ORD-CLIENT-TWO');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.invoices.index', ['keyword' => $client->email]))
            ->assertOk()
            ->assertSee('INV-CLIENT-ONE')
            ->assertDontSee('INV-CLIENT-TWO');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tickets.index', ['keyword' => $client->username]))
            ->assertOk()
            ->assertSee('TICCLIENTONE')
            ->assertDontSee('TICCLIENTTWO');
    }

    public function test_admin_history_records_keep_soft_deleted_client_identity(): void
    {
        $admin = $this->admin();
        $client = $this->client('deleted-history-client', 'deleted-history@example.com');
        $order = $this->createOrder($client, 'ORD-DELETED-CLIENT');
        $invoice = $this->createInvoice($client, 'INV-DELETED-CLIENT');
        $ticket = $this->createTicket($client, 'TICDELETEDCLIENT', '已删除客户工单');
        $host = $this->createHost($client, $order);
        $client->delete();

        $this->assertSame('deleted-history-client', $order->fresh()->client?->username);
        $this->assertSame('deleted-history-client', $invoice->fresh()->client?->username);
        $this->assertSame('deleted-history-client', $ticket->fresh()->client?->username);
        $this->assertSame('deleted-history-client', $host->fresh()->client?->username);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('deleted-history-client');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('deleted-history-client');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('deleted-history-client');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.hosts.show', $host))
            ->assertOk()
            ->assertSee('deleted-history-client');
    }

    public function test_admin_related_lists_mark_soft_deleted_clients(): void
    {
        $admin = $this->admin();
        $client = $this->client('deleted-list-client', 'deleted-list@example.com');
        $order = $this->createOrder($client, 'ORD-DELETED-LIST');
        $invoice = $this->createInvoice($client, 'INV-DELETED-LIST');
        $ticket = $this->createTicket($client, 'TICDELETEDLIST', '已删除客户列表工单');
        $client->delete();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.index', ['keyword' => $client->username]))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('deleted-list-client')
            ->assertSee('已删除');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.invoices.index', ['keyword' => $client->email]))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('deleted-list-client')
            ->assertSee('已删除');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tickets.index', ['keyword' => $client->username]))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('deleted-list-client')
            ->assertSee('已删除');
    }

    public function test_admin_related_list_filters_ignore_array_query_values(): void
    {
        $admin = $this->admin();
        $client = $this->client('array-filter-client', 'array-filter@example.com');
        $this->createOrder($client, 'ORD-ARRAY-FILTER');
        $this->createInvoice($client, 'INV-ARRAY-FILTER');
        $this->createTicket($client, 'TICARRAYFILTER', '数组筛选工单');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.clients.index', ['keyword' => [$client->username]]))
            ->assertOk()
            ->assertSee($client->username);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.index', [
                'keyword' => [$client->username],
                'status' => ['Pending'],
            ]))
            ->assertOk()
            ->assertSee('ORD-ARRAY-FILTER');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.invoices.index', [
                'keyword' => [$client->email],
                'status' => ['Unpaid'],
            ]))
            ->assertOk()
            ->assertSee('INV-ARRAY-FILTER');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tickets.index', ['keyword' => [$client->username]]))
            ->assertOk()
            ->assertSee('TICARRAYFILTER');
    }

    public function test_admin_cannot_delete_client_with_unfinished_hosts(): void
    {
        $admin = $this->admin();
        $client = $this->client('delete-blocked-client', 'delete-blocked@example.com');
        $order = $this->createOrder($client, 'ORD-DELETE-BLOCKED');
        $this->createHost($client, $order, ['status' => 'Active']);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.clients.destroy', $client))
            ->assertRedirect(route('admin.clients.show', $client))
            ->assertSessionHas('error', '客户存在未完结服务，不能删除');

        $this->assertNull($client->fresh()->deleted_at);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'client.delete',
            'target_id' => $client->id,
            'result' => 'failed',
            'error' => '客户存在未完结服务，不能删除',
        ]);
    }

    public function test_client_delete_rechecks_latest_blocking_hosts_inside_transaction(): void
    {
        $client = $this->client('delete-stale-client', 'delete-stale@example.com');
        $staleClient = $client->fresh();
        $order = $this->createOrder($client, 'ORD-DELETE-STALE');
        $this->createHost($client, $order, ['status' => 'Active']);

        $this->assertFalse(app(\App\Modules\User\Services\ClientService::class)->delete($staleClient));
        $this->assertNull($client->fresh()->deleted_at);
    }

    public function test_admin_can_delete_client_when_hosts_are_finished(): void
    {
        $admin = $this->admin();
        $client = $this->client('delete-finished-client', 'delete-finished@example.com');
        $order = $this->createOrder($client, 'ORD-DELETE-FINISHED');
        $this->createHost($client, $order, ['status' => 'Terminated']);
        $this->createHost($client, $order, ['status' => 'Cancelled']);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.clients.destroy', $client))
            ->assertRedirect(route('admin.clients.index'))
            ->assertSessionHas('status', '客户已删除');

        $this->assertNotNull($client->fresh()->deleted_at);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'client.delete',
            'target_id' => $client->id,
            'result' => 'success',
        ]);
    }

    private function client(string $username = 'client-security', string $email = 'client-security@example.com'): Client
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => 1,
            'phone_code' => '86',
            'phone' => '13800138010',
        ]);
    }

    private function admin(): AdminUser
    {
        $this->seed();

        return AdminUser::query()->where('username', 'admin')->firstOrFail();
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
    }

    private function installSms(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('sms', 'aliyun');
        $manager->enable('aliyun');
        \App\Models\Plugin::query()->where('name', 'aliyun')->update(['config' => ['mock' => true]]);
    }

    private function createOrder(Client $client, string $number): Order
    {
        return Order::query()->create([
            'client_id' => $client->id,
            'order_number' => $number,
            'status' => 'Pending',
            'amount' => 100,
            'currency_id' => 1,
        ]);
    }

    private function createInvoice(Client $client, string $number): Invoice
    {
        return Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => $number,
            'subtotal' => 100,
            'total' => 100,
            'status' => 'Unpaid',
        ]);
    }

    private function createTicket(Client $client, string $number, string $subject): Ticket
    {
        $department = TicketDepartment::query()->firstOrCreate(['name' => '客户关联测试部门']);
        $status = TicketStatus::query()->firstOrCreate(
            ['name' => 'Open'],
            ['is_default' => true]
        );

        return Ticket::query()->create([
            'ticket_number' => $number,
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => $subject,
            'message' => '测试工单',
        ]);
    }

    private function createHost(Client $client, Order $order, array $overrides = []): Host
    {
        $group = ProductGroup::query()->firstOrCreate(['name' => '客户关联测试产品组']);
        $product = Product::query()->create([
            'group_id' => $group->id,
            'name' => '客户关联测试产品',
            'type' => 'vps',
        ]);

        return Host::query()->create(array_merge([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'first_payment_amount' => 100,
            'recurring_amount' => 100,
            'status' => 'Active',
        ], $overrides));
    }
}
