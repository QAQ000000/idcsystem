<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketSla;
use App\Modules\Ticket\Models\TicketSlaLog;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\SlaService;
use App\Modules\Ticket\Services\TicketService;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_creation_applies_matching_sla_rule(): void
    {
        $client = $this->client();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);
        TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);
        $sla = TicketSla::query()->create([
            'department_id' => $department->id,
            'priority' => 'Medium',
            'response_time_minutes' => 30,
            'resolution_time_minutes' => 120,
            'active' => true,
        ]);

        $ticket = app(TicketService::class)->create($client, $department->id, '无法登录', '请协助排查');
        $log = $ticket->fresh('slaLog')->slaLog;

        $this->assertSame((int) $sla->id, (int) $log->sla_id);
        $this->assertTrue($ticket->created_at->copy()->addMinutes(30)->equalTo($log->response_due_at));
        $this->assertTrue($ticket->created_at->copy()->addMinutes(120)->equalTo($log->resolution_due_at));
    }

    public function test_admin_first_reply_records_response_breach(): void
    {
        $ticket = $this->ticketWithSla(['response_time_minutes' => 1, 'resolution_time_minutes' => 60]);
        $ticket->slaLog->forceFill(['response_due_at' => now()->subMinute()])->save();

        app(TicketService::class)->reply($ticket, 'admin', 1, '后台回复');

        $log = $ticket->fresh('slaLog')->slaLog;
        $this->assertNotNull($log->first_response_at);
        $this->assertTrue($log->response_breached);
    }

    public function test_closing_ticket_records_resolution_status(): void
    {
        $ticket = $this->ticketWithSla(['response_time_minutes' => 30, 'resolution_time_minutes' => 1]);
        $ticket->slaLog->forceFill(['resolution_due_at' => now()->subMinute()])->save();
        TicketStatus::query()->create(['name' => 'Closed', 'is_default' => false]);

        $this->assertTrue(app(TicketService::class)->close($ticket));

        $log = $ticket->fresh('slaLog')->slaLog;
        $this->assertNotNull($log->resolved_at);
        $this->assertTrue($log->resolution_breached);
    }

    public function test_sla_breach_command_marks_overdue_logs(): void
    {
        $ticket = $this->ticketWithSla(['response_time_minutes' => 1, 'resolution_time_minutes' => 2]);
        $ticket->slaLog->forceFill([
            'response_due_at' => now()->subMinutes(2),
            'resolution_due_at' => now()->subMinute(),
        ])->save();

        $this->artisan('tickets:check-sla-breaches')->assertExitCode(0);

        $log = $ticket->fresh('slaLog')->slaLog;
        $this->assertTrue($log->response_breached);
        $this->assertTrue($log->resolution_breached);
    }

    public function test_admin_can_manage_sla_rules(): void
    {
        $admin = $this->admin();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticket-slas.store'), [
                'department_id' => $department->id,
                'priority' => 'High',
                'response_time_minutes' => 15,
                'resolution_time_minutes' => 90,
                'active' => 1,
            ])
            ->assertRedirect(route('admin.ticket-slas.index'));

        $this->assertDatabaseHas('ticket_slas', [
            'department_id' => $department->id,
            'priority' => 'High',
            'response_time_minutes' => 15,
            'resolution_time_minutes' => 90,
            'active' => true,
        ]);
    }

    private function ticketWithSla(array $slaData): Ticket
    {
        $client = $this->client();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);
        $status = TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);
        TicketSla::query()->create([
            'department_id' => $department->id,
            'priority' => 'Medium',
            'response_time_minutes' => $slaData['response_time_minutes'],
            'resolution_time_minutes' => $slaData['resolution_time_minutes'],
            'active' => true,
        ]);

        $ticket = Ticket::query()->create([
            'ticket_number' => 'TIC' . now()->format('YmdHis') . random_int(100, 999),
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => '测试工单',
            'message' => '测试内容',
            'priority' => 'Medium',
        ]);

        app(SlaService::class)->applyToTicket($ticket);

        return $ticket->fresh('slaLog');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'ticket-sla-admin-' . random_int(1000, 9999),
            'email' => 'ticket-sla-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(): Client
    {
        return Client::query()->create([
            'username' => 'ticket-sla-client-' . random_int(1000, 9999),
            'email' => 'ticket-sla-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
    }
}
