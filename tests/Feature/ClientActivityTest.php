<?php

namespace Tests\Feature;

use App\Models\ClientActivityLog;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Services\TicketService;
use App\Modules\User\Models\Client;
use App\Modules\User\Services\AuthService;
use App\Modules\User\Services\ClientService;
use App\Services\ClientActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_service_masks_sensitive_meta(): void
    {
        $client = $this->client();

        app(ClientActivityService::class)->log($client, 'test.action', '测试活动 password=secret123', [
            'token' => 'abc',
            'nested' => [
                'private_key' => 'secret-key',
                'safe' => 'value',
            ],
        ]);

        $activity = ClientActivityLog::query()->where('client_id', $client->id)->firstOrFail();

        $this->assertSame('[FILTERED]', $activity->meta['token']);
        $this->assertSame('[FILTERED]', $activity->meta['nested']['private_key']);
        $this->assertSame('value', $activity->meta['nested']['safe']);
        $this->assertStringContainsString('password=[FILTERED]', $activity->description);
    }

    public function test_register_and_login_write_client_activities_once(): void
    {
        $auth = app(AuthService::class);

        $client = $auth->register([
            'username' => 'activity-register',
            'email' => 'activity-register@example.com',
            'password' => 'client123456',
        ]);
        $client->update(['status' => 1]);

        $this->assertDatabaseHas('client_activity_logs', [
            'client_id' => $client->id,
            'action' => 'auth.registered',
        ]);

        $loggedIn = $auth->login('activity-register@example.com', 'client123456');
        $this->assertNotNull($loggedIn);
        $this->assertDatabaseMissing('client_activity_logs', [
            'client_id' => $client->id,
            'action' => 'auth.login',
        ]);

        $auth->recordLogin($loggedIn);
        $auth->recordLogin($loggedIn);

        $this->assertSame(2, ClientActivityLog::query()
            ->where('client_id', $client->id)
            ->where('action', 'auth.login')
            ->count());
    }

    public function test_profile_activity_page_lists_real_changes_only(): void
    {
        $client = $this->client(phone: '13800138000');

        $payload = [
            'phone_code' => '86',
            'phone' => '13800138001',
            'locale' => 'zh_CN',
        ];

        $this->actingAs($client, 'client')
            ->put(route('client.account.profile.update'), $payload)
            ->assertRedirect(route('client.account.profile'));

        $this->assertDatabaseHas('client_activity_logs', [
            'client_id' => $client->id,
            'action' => 'profile.updated',
        ]);

        $this->actingAs($client->fresh(), 'client')
            ->put(route('client.account.profile.update'), $payload)
            ->assertRedirect(route('client.account.profile'));

        $this->assertSame(1, ClientActivityLog::query()
            ->where('client_id', $client->id)
            ->where('action', 'profile.updated')
            ->count());

        $this->actingAs($client->fresh(), 'client')
            ->get(route('client.account.activity'))
            ->assertOk()
            ->assertSee('profile.updated')
            ->assertSee('账户资料已更新');
    }

    public function test_invoice_payment_credit_add_and_ticket_create_write_activities(): void
    {
        $client = $this->client();
        $invoice = $this->invoice($client, 100);

        $this->assertTrue(app(InvoiceService::class)->markAsPaid($invoice, 'manual', 'TXN-ACTIVITY-1'));
        $this->assertTrue(app(ClientService::class)->addCredit($client, 25, '测试充值'));

        $department = TicketDepartment::query()->create([
            'name' => '活动日志支持',
            'allow_client_open' => true,
        ]);
        app(TicketService::class)->create($client, $department->id, '活动日志测试工单', '测试内容');

        $this->assertDatabaseHas('client_activity_logs', [
            'client_id' => $client->id,
            'action' => 'invoice.paid',
        ]);
        $this->assertDatabaseHas('client_activity_logs', [
            'client_id' => $client->id,
            'action' => 'credit.added',
        ]);
        $this->assertDatabaseHas('client_activity_logs', [
            'client_id' => $client->id,
            'action' => 'ticket.created',
        ]);
    }

    private function client(string $username = 'activity-client', string $email = 'activity-client@example.com', string $phone = '13800138000'): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
            'phone_code' => '86',
            'phone' => $phone,
            'locale' => 'zh_CN',
        ]);
    }

    private function invoice(Client $client, float $amount): Invoice
    {
        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-ACT-' . random_int(1000, 9999),
            'subtotal' => $amount,
            'tax' => 0,
            'tax_rate' => 0,
            'credit_used' => 0,
            'total' => $amount,
            'status' => 'Unpaid',
            'due_date' => now()->addDays(7),
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'type' => 'product',
            'description' => '活动日志测试账单',
            'amount' => $amount,
        ]);

        return $invoice;
    }

}
