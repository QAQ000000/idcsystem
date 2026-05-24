<?php

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Models\Plugin;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketService;
use App\Modules\User\Models\Client;
use App\Plugins\Core\PluginManager;
use App\Services\SettingsService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('sms'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'aliyun'));
        $this->assertTrue($manager->install('sms', 'aliyun'));
        $this->assertDatabaseHas('plugins', ['name' => 'aliyun', 'type' => 'sms', 'status' => 0]);
        $this->assertTrue($manager->enable('aliyun'));
        $this->assertDatabaseHas('plugins', ['name' => 'aliyun', 'status' => 1]);
    }

    public function test_sms_service_sends_with_enabled_plugin_and_logs_result(): void
    {
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSms();

        $sent = app(SmsService::class)->send('13800138000', 'invoice_created', [
            'client_name' => '张三',
            'invoice_number' => 'INV001',
            'amount' => '99.00',
        ], ['async' => false]);

        $this->assertTrue($sent);
        $this->assertDatabaseHas('sms_logs', [
            'phone' => '13800138000',
            'template' => 'invoice_created',
            'provider' => 'aliyun',
            'status' => 'sent',
            'success' => true,
        ]);
    }

    public function test_sms_service_can_create_pending_log_for_async_sending(): void
    {
        Bus::fake();
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->installSms();
        app(SettingsService::class)->set('sms_queue_enabled', true, 'sms');

        $sent = app(SmsService::class)->send('13800138001', 'invoice_paid', [
            'client_name' => '李四',
            'invoice_number' => 'INV002',
            'amount' => '88.00',
        ]);

        $this->assertTrue($sent);
        Bus::assertDispatched(SendSmsJob::class);
        $this->assertDatabaseHas('sms_logs', [
            'phone' => '13800138001',
            'template' => 'invoice_paid',
            'status' => 'pending',
            'success' => false,
        ]);
    }

    public function test_send_sms_job_marks_log_as_sent(): void
    {
        $this->installSms();
        $log = SmsLog::query()->create([
            'phone' => '13800138002',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => '支付成功',
            'provider' => 'aliyun',
            'status' => 'pending',
            'success' => false,
            'payload' => [],
            'attempts' => 0,
        ]);

        app(SendSmsJob::class, ['smsLogId' => $log->id])->handle(app(SmsService::class));

        $this->assertDatabaseHas('sms_logs', [
            'id' => $log->id,
            'status' => 'sent',
            'success' => true,
        ]);
    }

    public function test_send_sms_job_claims_log_once_when_dispatched_more_than_once(): void
    {
        $this->installSms();
        $log = SmsLog::query()->create([
            'phone' => '13800138012',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => '支付成功',
            'provider' => 'aliyun',
            'status' => 'pending',
            'success' => false,
            'payload' => [],
            'attempts' => 0,
        ]);
        $job = app(SendSmsJob::class, ['smsLogId' => $log->id]);

        $job->handle(app(SmsService::class));
        $job->handle(app(SmsService::class));

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertTrue($log->success);
        $this->assertSame(1, $log->attempts);
    }

    public function test_send_sms_job_marks_log_failed_and_throws_when_provider_missing(): void
    {
        $log = SmsLog::query()->create([
            'phone' => '13800138003',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => '支付成功',
            'provider' => 'missing',
            'status' => 'pending',
            'success' => false,
            'payload' => [],
            'attempts' => 0,
        ]);

        try {
            app(SendSmsJob::class, ['smsLogId' => $log->id])->handle(app(SmsService::class));
            $this->fail('Expected SMS job to throw when delivery fails.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SMS notification failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('sms_logs', [
            'id' => $log->id,
            'status' => 'failed',
            'success' => false,
        ]);
        $this->assertNotNull(SmsLog::query()->findOrFail($log->id)->error);
    }

    public function test_template_variables_are_rendered(): void
    {
        $rendered = app(SmsService::class)->render(
            '您好 {{client_name}}，账单 {{invoice_number}} 金额 {{amount}}',
            ['client_name' => '王五', 'invoice_number' => 'INV003', 'amount' => '66.00']
        );

        $this->assertSame('您好 王五，账单 INV003 金额 66.00', $rendered);
    }

    public function test_invoice_payment_and_ticket_actions_trigger_sms_logs(): void
    {
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSms();
        $this->installSmtp();
        $client = $this->client();

        $invoice = app(InvoiceService::class)->generate($client, [[
            'type' => 'product',
            'description' => '测试产品',
            'amount' => 100,
        ]]);

        $this->assertDatabaseHas('sms_logs', [
            'phone' => $client->phone,
            'template' => 'invoice_created',
            'status' => 'sent',
        ]);

        $this->installManualPay();
        app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'status' => 'paid',
            'trans_id' => 'SMS-PAY-001',
        ]);

        $this->assertDatabaseHas('sms_logs', [
            'phone' => $client->phone,
            'template' => 'invoice_paid',
            'status' => 'sent',
        ]);

        $ticket = $this->ticket($client);
        app(TicketService::class)->reply($ticket, 'admin', 1, '后台回复内容');

        $this->assertDatabaseHas('sms_logs', [
            'phone' => $client->phone,
            'template' => 'ticket_replied',
            'status' => 'sent',
        ]);
        $this->assertSame(3, SmsLog::query()->where('success', true)->count());
    }

    public function test_admin_can_view_and_retry_sms_logs(): void
    {
        $admin = $this->admin();
        $log = SmsLog::query()->create([
            'phone' => '13800138004',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => '支付成功',
            'provider' => 'aliyun',
            'status' => 'failed',
            'success' => false,
            'error' => 'test error',
            'payload' => [],
            'attempts' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.sms-logs.index'))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.sms-logs.show', $log))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.sms-logs.retry', $log))
            ->assertRedirect(route('admin.sms-logs.show', $log));
    }

    public function test_retry_failed_template_sms_rerenders_current_template(): void
    {
        $this->installSms();
        SmsTemplate::query()->create([
            'name' => 'invoice_created',
            'content' => '旧短信 {{invoice_number}}',
            'enabled' => false,
        ]);

        $this->assertFalse(app(SmsService::class)->send('13800138888', 'invoice_created', [
            'invoice_number' => 'INV-SMS-RETRY',
        ], ['async' => false]));

        $log = SmsLog::query()->where('phone', '13800138888')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('', $log->content);

        SmsTemplate::query()->where('name', 'invoice_created')->update([
            'content' => '新短信 {{invoice_number}}',
            'enabled' => true,
        ]);

        $this->assertTrue(app(SmsService::class)->retry($log->fresh(), false));

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertSame('新短信 INV-SMS-RETRY', $log->content);
    }

    public function test_sent_sms_log_cannot_be_retried(): void
    {
        $log = SmsLog::query()->create([
            'phone' => '13800138999',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => '已发送',
            'provider' => 'aliyun',
            'status' => 'sent',
            'success' => true,
            'payload' => [],
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        $this->assertFalse(app(SmsService::class)->retry($log, false));
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame(1, $log->fresh()->attempts);
    }

    public function test_admin_cannot_retry_sent_sms_log_by_posting_directly(): void
    {
        $admin = $this->admin();
        $log = SmsLog::query()->create([
            'phone' => '13800138666',
            'template' => 'invoice_paid',
            'template_code' => 'invoice_paid',
            'content' => '已发送',
            'provider' => 'aliyun',
            'status' => 'sent',
            'success' => true,
            'payload' => [],
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.sms-logs.retry', $log))
            ->assertRedirect(route('admin.sms-logs.show', $log))
            ->assertSessionHas('error');

        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame(1, $log->fresh()->attempts);
    }

    public function test_sms_retry_stays_failed_when_template_is_unavailable(): void
    {
        $log = SmsLog::query()->create([
            'phone' => '13800138777',
            'template' => 'missing_template',
            'template_code' => 'missing_template',
            'content' => '',
            'provider' => 'aliyun',
            'status' => 'failed',
            'success' => false,
            'payload' => ['invoice_number' => 'INV-SMS-MISSING'],
            'error' => 'test error',
            'attempts' => 1,
        ]);

        $this->assertFalse(app(SmsService::class)->retry($log, false));
        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertSame('SMS template unavailable', $log->error);
    }

    private function installSms(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('sms', 'aliyun');
        $manager->enable('aliyun');
        Plugin::query()->where('name', 'aliyun')->update([
            'config' => ['mock' => true, 'sign_name' => 'IDC'],
        ]);
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
    }

    private function installManualPay(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('gateway', 'manual_pay');
        $manager->enable('manual_pay');
    }

    private function client(): Client
    {
        Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'sms-client',
            'email' => 'sms-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => 1,
            'phone_code' => '86',
            'phone' => '13800138005',
        ]);
    }

    private function ticket(Client $client): Ticket
    {
        $department = TicketDepartment::query()->create(['name' => '短信测试部门']);
        $status = TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);

        return Ticket::query()->create([
            'ticket_number' => 'TICSMS001',
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => '短信测试工单',
            'message' => '测试内容',
            'priority' => 'Medium',
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'admin-sms',
            'email' => 'admin-sms@example.com',
            'password' => Hash::make('admin123456'),
            'real_name' => '短信管理员',
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
