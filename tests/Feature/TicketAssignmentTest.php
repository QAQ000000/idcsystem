<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketService;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_creation_auto_assigns_by_least_active_rule(): void
    {
        $client = $this->client();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);
        TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);
        $busy = $this->admin(['assigned_ticket_count' => 5]);
        $available = $this->admin(['assigned_ticket_count' => 1]);
        TicketAssignmentRule::query()->create([
            'department_id' => $department->id,
            'strategy' => 'least_active',
            'admin_user_ids' => [$busy->id, $available->id],
            'active' => true,
        ]);

        $ticket = app(TicketService::class)->create($client, $department->id, '无法登录', '请协助排查');

        $this->assertSame((int) $available->id, (int) $ticket->fresh()->assigned_to);
        $this->assertSame(2, (int) $available->fresh()->assigned_ticket_count);
        $this->assertSame(5, (int) $busy->fresh()->assigned_ticket_count);
    }

    public function test_manual_reassignment_updates_admin_ticket_counts(): void
    {
        $client = $this->client();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);
        $status = TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);
        TicketStatus::query()->create(['name' => 'Closed', 'is_default' => false]);
        $first = $this->admin();
        $second = $this->admin();
        $ticket = \App\Modules\Ticket\Models\Ticket::query()->create([
            'ticket_number' => 'TIC' . now()->format('YmdHis') . random_int(100, 999),
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => '测试工单',
            'message' => '测试内容',
            'priority' => 'Medium',
        ]);

        $service = app(TicketService::class);
        $this->assertTrue($service->assign($ticket, (int) $first->id));
        $this->assertSame(1, (int) $first->fresh()->assigned_ticket_count);

        $this->assertTrue($service->assign($ticket->fresh(), (int) $second->id));
        $this->assertSame(0, (int) $first->fresh()->assigned_ticket_count);
        $this->assertSame(1, (int) $second->fresh()->assigned_ticket_count);

        $this->assertTrue($service->close($ticket->fresh()));
        $this->assertSame(0, (int) $second->fresh()->assigned_ticket_count);
    }

    public function test_admin_can_create_assignment_rule(): void
    {
        $operator = $this->admin();
        $assignee = $this->admin();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);

        $this->actingAs($operator, 'admin')
            ->post(route('admin.ticket-assignment-rules.store'), [
                'department_id' => $department->id,
                'strategy' => 'round_robin',
                'admin_user_ids' => [$assignee->id],
                'active' => 1,
            ])
            ->assertRedirect(route('admin.ticket-assignment-rules.index'));

        $this->assertDatabaseHas('ticket_assignment_rules', [
            'department_id' => $department->id,
            'strategy' => 'round_robin',
            'active' => true,
        ]);
    }

    private function admin(array $overrides = []): AdminUser
    {
        $admin = AdminUser::query()->create(array_merge([
            'username' => 'ticket-assignment-admin-' . random_int(1000, 9999),
            'email' => 'ticket-assignment-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ], $overrides));

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(): Client
    {
        return Client::query()->create([
            'username' => 'ticket-assignment-client-' . random_int(1000, 9999),
            'email' => 'ticket-assignment-client-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
    }
}
