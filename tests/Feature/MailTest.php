<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Plugin;
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
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    public function test_smtp_plugin_can_be_scanned_installed_and_enabled(): void
    {
        $manager = app(PluginManager::class);
        $scan = collect($manager->scan('email'));

        $this->assertTrue($scan->contains(fn (array $plugin) => $plugin['name'] === 'smtp'));
        $this->assertTrue($manager->install('email', 'smtp'));
        $this->assertDatabaseHas('plugins', ['name' => 'smtp', 'type' => 'email', 'status' => 0]);
        $this->assertTrue($manager->enable('smtp'));
        $this->assertDatabaseHas('plugins', ['name' => 'smtp', 'status' => 1]);
    }

    public function test_mail_service_sends_with_enabled_plugin_and_logs_result(): void
    {
        Mail::fake();
        $this->installSmtp();

        $sent = app(MailService::class)->send('client@example.com', 'Test Subject', '<p>Hello</p>', ['async' => false]);

        $this->assertTrue($sent);
        $this->assertDatabaseHas('email_logs', [
            'to' => 'client@example.com',
            'subject' => 'Test Subject',
            'provider' => 'smtp',
            'status' => 'sent',
            'success' => true,
        ]);
    }

    public function test_mail_service_can_create_pending_log_for_async_sending(): void
    {
        Bus::fake();
        $this->installSmtp();
        $this->setMailQueueEnabled(true);

        $sent = app(MailService::class)->send('queue@example.com', 'Async Subject', '<p>Async</p>');

        $this->assertTrue($sent);
        Bus::assertDispatched(\App\Jobs\SendEmailJob::class);
        $this->assertDatabaseHas('email_logs', [
            'to' => 'queue@example.com',
            'subject' => 'Async Subject',
            'status' => 'pending',
            'success' => false,
        ]);
    }

    public function test_send_email_job_marks_log_as_sent(): void
    {
        Mail::fake();
        $this->installSmtp();
        $log = EmailLog::query()->create([
            'to' => 'job@example.com',
            'subject' => 'Job Subject',
            'body' => '<p>Job</p>',
            'provider' => 'smtp',
            'status' => 'pending',
            'success' => false,
            'payload' => [],
            'attempts' => 0,
        ]);

        app(\App\Jobs\SendEmailJob::class, ['emailLogId' => $log->id])->handle(app(MailService::class));

        $this->assertDatabaseHas('email_logs', [
            'id' => $log->id,
            'status' => 'sent',
            'success' => true,
        ]);
    }

    public function test_send_email_job_marks_log_failed_when_provider_missing(): void
    {
        $log = EmailLog::query()->create([
            'to' => 'fail@example.com',
            'subject' => 'Fail Subject',
            'body' => '<p>Fail</p>',
            'provider' => 'missing',
            'status' => 'pending',
            'success' => false,
            'payload' => [],
            'attempts' => 0,
        ]);

        app(\App\Jobs\SendEmailJob::class, ['emailLogId' => $log->id])->handle(app(MailService::class));

        $this->assertDatabaseHas('email_logs', [
            'id' => $log->id,
            'status' => 'failed',
            'success' => false,
        ]);
        $this->assertNotNull(EmailLog::query()->findOrFail($log->id)->error);
    }

    public function test_template_variables_are_rendered(): void
    {
        $rendered = app(MailService::class)->render(
            '您好 {{client_name}}，账单 {{invoice_number}} 金额 {{amount}}',
            ['client_name' => '张三', 'invoice_number' => 'INV001', 'amount' => '99.00']
        );

        $this->assertSame('您好 张三，账单 INV001 金额 99.00', $rendered);
    }

    public function test_invoice_payment_and_ticket_actions_trigger_mail_logs(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        $this->installSmtp();
        $client = $this->client();

        $invoice = app(InvoiceService::class)->generate($client, [[
            'type' => 'product',
            'description' => '测试产品',
            'amount' => 100,
        ]]);

        $this->assertDatabaseHas('email_logs', [
            'to' => $client->email,
            'template' => 'invoice_created',
            'success' => true,
        ]);

        $this->installManualPay();
        app(PaymentService::class)->handleCallback('manual_pay', [
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'status' => 'paid',
            'trans_id' => 'MAIL-PAY-001',
        ]);

        $this->assertDatabaseHas('email_logs', [
            'to' => $client->email,
            'template' => 'invoice_paid',
            'success' => true,
        ]);

        $ticket = $this->ticket($client);
        app(TicketService::class)->reply($ticket, 'admin', 1, '后台回复内容');

        $this->assertDatabaseHas('email_logs', [
            'to' => $client->email,
            'template' => 'ticket_replied',
            'success' => true,
        ]);
        $this->assertSame(3, EmailLog::query()->where('success', true)->count());
    }

    public function test_admin_can_view_and_retry_email_logs(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $log = EmailLog::query()->create([
            'to' => 'admin-view@example.com',
            'subject' => 'Admin View',
            'body' => '<p>View</p>',
            'provider' => 'smtp',
            'status' => 'failed',
            'success' => false,
            'error' => 'test error',
            'payload' => [],
            'attempts' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.email-logs.index'))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.email-logs.show', $log))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.email-logs.retry', $log))
            ->assertRedirect(route('admin.email-logs.show', $log));
    }

    private function installSmtp(): void
    {
        $manager = app(PluginManager::class);
        $manager->install('email', 'smtp');
        $manager->enable('smtp');
        Plugin::query()->where('name', 'smtp')->update([
            'config' => ['host' => 'smtp.example.com', 'port' => 587],
        ]);
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
            'username' => 'mail-client',
            'email' => 'mail-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => 1,
        ]);
    }

    private function ticket(Client $client): Ticket
    {
        $department = TicketDepartment::query()->create(['name' => '邮件测试部门']);
        $status = TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);

        return Ticket::query()->create([
            'ticket_number' => 'TICMAIL001',
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => '邮件测试工单',
            'message' => '测试内容',
            'priority' => 'Medium',
        ]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::query()->create([
            'username' => 'admin-mail',
            'email' => 'admin-mail@example.com',
            'password' => Hash::make('admin123456'),
            'real_name' => '邮件管理员',
            'status' => 1,
        ]);
    }

    private function setMailQueueEnabled(bool $enabled): void
    {
        app(\App\Services\SettingsService::class)->set('mail_queue_enabled', $enabled, 'mail');
    }
}
