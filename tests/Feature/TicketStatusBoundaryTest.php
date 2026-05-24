<?php

namespace Tests\Feature;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketReply;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketService;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketStatusBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_creation_creates_default_status_when_status_table_is_empty(): void
    {
        $client = $this->client();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);

        $ticket = app(TicketService::class)->create($client, $department->id, '无法登录', '请协助排查');

        $this->assertDatabaseHas('ticket_statuses', ['name' => 'Open', 'is_default' => true]);
        $this->assertSame((int) TicketStatus::query()->where('name', 'Open')->value('id'), (int) $ticket->status_id);
    }

    public function test_ticket_change_status_rejects_missing_status(): void
    {
        $ticket = $this->ticket();

        $this->assertFalse(app(TicketService::class)->changeStatus($ticket, 999999));
        $this->assertNotSame(999999, (int) $ticket->fresh()->status_id);
    }

    public function test_ticket_status_name_must_be_unique(): void
    {
        TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);

        $this->expectException(QueryException::class);

        TicketStatus::query()->create(['name' => 'Open', 'is_default' => false]);
    }

    public function test_ticket_close_fails_when_closed_status_is_missing(): void
    {
        $ticket = $this->ticket();

        $this->assertFalse(app(TicketService::class)->close($ticket));
        $this->assertSame((int) $ticket->status_id, (int) $ticket->fresh()->status_id);
    }

    public function test_closed_ticket_rejects_service_reply_and_status_reopen(): void
    {
        $ticket = $this->closedTicket();
        $open = TicketStatus::query()->where('name', 'Open')->firstOrFail();

        $this->assertNull(app(TicketService::class)->reply($ticket, 'client', (int) $ticket->client_id, '继续追问'));
        $this->assertFalse(app(TicketService::class)->changeStatus($ticket, (int) $open->id));
        $this->assertSame('Closed', $ticket->fresh('status')->status->name);
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_admin_cannot_reply_closed_ticket(): void
    {
        $ticket = $this->closedTicket();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.tickets.reply', $ticket), ['message' => '后台继续回复'])
            ->assertRedirect(route('admin.tickets.show', $ticket))
            ->assertSessionHas('error', '已关闭工单不允许继续回复');

        $this->assertSame('Closed', $ticket->fresh('status')->status->name);
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'ticket.reply',
            'target_id' => $ticket->id,
            'result' => 'failed',
        ]);
    }

    public function test_client_cannot_reply_closed_ticket(): void
    {
        $ticket = $this->closedTicket();

        $this->actingAs($ticket->client, 'client')
            ->post(route('client.tickets.reply', $ticket), ['message' => '客户继续回复'])
            ->assertRedirect(route('client.tickets.show', $ticket))
            ->assertSessionHasErrors('ticket');

        $this->assertSame('Closed', $ticket->fresh('status')->status->name);
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());
    }

    private function ticket(): Ticket
    {
        $client = $this->client();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);
        $status = TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);

        return Ticket::query()->create([
            'ticket_number' => 'TIC' . now()->format('YmdHis') . random_int(100, 999),
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $status->id,
            'subject' => '测试工单',
            'message' => '测试内容',
            'priority' => 'Medium',
        ]);
    }

    private function closedTicket(): Ticket
    {
        $client = $this->client();
        $department = TicketDepartment::query()->create(['name' => '支持部门']);
        TicketStatus::query()->create(['name' => 'Open', 'is_default' => true]);
        $closed = TicketStatus::query()->create(['name' => 'Closed', 'is_default' => false]);

        return Ticket::query()->create([
            'ticket_number' => 'TIC' . now()->format('YmdHis') . random_int(100, 999),
            'client_id' => $client->id,
            'department_id' => $department->id,
            'status_id' => $closed->id,
            'subject' => '已关闭工单',
            'message' => '测试内容',
            'priority' => 'Medium',
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'ticket-boundary-admin',
            'email' => 'ticket-boundary-admin@example.com',
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
            'username' => 'ticket-boundary-client',
            'email' => 'ticket-boundary-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);
    }
}
