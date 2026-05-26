<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketReply;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketService;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\User\Models\Client;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
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

    public function test_ticket_service_rejects_inactive_client_creation(): void
    {
        $client = $this->client();
        $client->update(['status' => 2]);
        $department = TicketDepartment::query()->create(['name' => '支持部门']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('客户账号状态不允许创建工单');

        app(TicketService::class)->create($client->fresh(), $department->id, '无法登录', '请协助排查');
    }

    public function test_client_cannot_create_ticket_in_department_closed_to_clients(): void
    {
        $client = $this->client();
        $openDepartment = TicketDepartment::query()->create(['name' => '客户支持', 'allow_client_open' => true]);
        $closedDepartment = TicketDepartment::query()->create(['name' => '内部处理', 'allow_client_open' => false]);

        $this->actingAs($client, 'client')
            ->get(route('client.tickets.create'))
            ->assertOk()
            ->assertSee('客户支持')
            ->assertDontSee('内部处理');

        $this->actingAs($client, 'client')
            ->post(route('client.tickets.store'), [
                'department_id' => $closedDepartment->id,
                'subject' => '错误部门',
                'message' => '不应创建成功',
            ])
            ->assertRedirect(route('client.tickets.create'))
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('tickets', [
            'client_id' => $client->id,
            'department_id' => $closedDepartment->id,
        ]);
        $this->assertDatabaseMissing('tickets', [
            'client_id' => $client->id,
            'department_id' => $openDepartment->id,
            'subject' => '错误部门',
        ]);
    }

    public function test_client_can_upload_and_download_ticket_reply_attachment(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket();

        $this->actingAs($ticket->client, 'client')
            ->post(route('client.tickets.reply', $ticket), [
                'message' => '带附件回复',
                'attachments' => [
                    UploadedFile::fake()->create('evidence.txt', 4, 'text/plain'),
                ],
            ])
            ->assertRedirect(route('client.tickets.show', $ticket));

        $reply = TicketReply::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame('evidence.txt', $reply->attachment[0]['name']);
        Storage::disk('local')->assertExists($reply->attachment[0]['path']);

        $this->actingAs($ticket->client, 'client')
            ->get(route('client.tickets.attachments.download', [$ticket, $reply, 0]))
            ->assertOk();
    }

    public function test_client_cannot_download_other_client_ticket_attachment(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket();
        $other = Client::query()->create([
            'username' => 'ticket-other-client',
            'email' => 'ticket-other-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
        ]);

        $this->actingAs($ticket->client, 'client')
            ->post(route('client.tickets.reply', $ticket), [
                'message' => '带附件回复',
                'attachments' => [
                    UploadedFile::fake()->create('private.txt', 4, 'text/plain'),
                ],
            ]);
        $reply = TicketReply::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();

        $this->actingAs($other, 'client')
            ->get(route('client.tickets.attachments.download', [$ticket, $reply, 0]))
            ->assertForbidden();
    }

    public function test_admin_can_upload_and_download_ticket_reply_attachment(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.tickets.reply', $ticket), [
                'message' => '后台附件回复',
                'attachments' => [
                    UploadedFile::fake()->create('admin-note.pdf', 8, 'application/pdf'),
                ],
            ])
            ->assertRedirect(route('admin.tickets.show', $ticket));

        $reply = TicketReply::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame('admin-note.pdf', $reply->attachment[0]['name']);
        Storage::disk('local')->assertExists($reply->attachment[0]['path']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.tickets.attachments.download', [$ticket, $reply, 0]))
            ->assertOk();
    }

    public function test_ticket_service_rejects_inactive_client_reply(): void
    {
        $ticket = $this->ticket();
        $ticket->client->update(['status' => 2]);

        $this->assertNull(app(TicketService::class)->reply($ticket->fresh(['client']), 'client', (int) $ticket->client_id, '继续追问'));
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());
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

    public function test_ticket_reply_rechecks_latest_closed_status_inside_transaction(): void
    {
        $ticket = $this->ticket()->fresh(['client', 'status']);
        TicketStatus::query()->create(['name' => 'Closed', 'is_default' => false]);
        $staleTicket = $ticket->replicate();
        $staleTicket->setRawAttributes($ticket->getAttributes(), true);
        $staleTicket->exists = true;
        $staleTicket->setRelation('client', $ticket->client);
        $staleTicket->setRelation('status', $ticket->status);

        $this->assertTrue(app(TicketService::class)->close($ticket));

        $this->assertNull(app(TicketService::class)->reply($staleTicket, 'client', (int) $ticket->client_id, '旧页面继续回复'));
        $this->assertSame('Closed', $ticket->fresh('status')->status->name);
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_failed_ticket_reply_does_not_create_notification_logs(): void
    {
        $ticket = $this->closedTicket()->fresh(['client', 'status']);

        $this->assertNull(app(TicketService::class)->reply($ticket, 'client', (int) $ticket->client_id, '关闭后继续回复'));
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, EmailLog::query()->where('template', 'ticket_replied')->count());
        $this->assertSame(0, SmsLog::query()->where('template', 'ticket_replied')->count());
    }

    public function test_ticket_reply_is_not_blocked_when_notification_service_fails(): void
    {
        $ticket = $this->ticket()->fresh(['client', 'status']);
        $this->instance(NotificationService::class, new class extends NotificationService {
            public function notifyClient(Client $client, string $event, array $variables = []): array
            {
                throw new \RuntimeException('notification service unavailable');
            }
        });
        Log::shouldReceive('warning')
            ->once()
            ->with('Client notification failed without blocking workflow.', \Mockery::on(
                fn (array $context): bool => $context['workflow'] === 'ticket.reply'
                    && $context['event'] === 'ticket_replied'
                    && (int) $context['client_id'] === (int) $ticket->client_id
                    && $context['error'] === 'notification service unavailable'
            ));

        $reply = app(TicketService::class)->reply($ticket, 'admin', 1, '后台回复内容');

        $this->assertNotNull($reply);
        $this->assertDatabaseHas('ticket_replies', [
            'ticket_id' => $ticket->id,
            'author_type' => 'admin',
            'message' => '后台回复内容',
        ]);
    }

    public function test_ticket_status_change_rechecks_latest_closed_status_inside_transaction(): void
    {
        $ticket = $this->ticket()->fresh(['status']);
        $open = $ticket->status;
        TicketStatus::query()->create(['name' => 'Closed', 'is_default' => false]);
        $staleTicket = $ticket->replicate();
        $staleTicket->setRawAttributes($ticket->getAttributes(), true);
        $staleTicket->exists = true;
        $staleTicket->setRelation('status', $open);

        $this->assertTrue(app(TicketService::class)->close($ticket));

        $this->assertFalse(app(TicketService::class)->changeStatus($staleTicket, (int) $open->id));
        $this->assertSame('Closed', $ticket->fresh('status')->status->name);
    }

    public function test_admin_cannot_reply_closed_ticket(): void
    {
        $ticket = $this->closedTicket();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('工单已关闭，不能继续回复。')
            ->assertDontSee('回复内容')
            ->assertDontSee(route('admin.tickets.close', $ticket), false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tickets.reply', $ticket), ['message' => '后台继续回复'])
            ->assertRedirect(route('admin.tickets.show', $ticket))
            ->assertSessionHas('error', '当前工单不允许继续回复');

        $this->assertSame('Closed', $ticket->fresh('status')->status->name);
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'ticket.reply',
            'target_id' => $ticket->id,
            'result' => 'failed',
        ]);
    }

    public function test_ticket_operation_panel_hides_actions_for_limited_admin(): void
    {
        $ticket = $this->ticket();
        $admin = AdminUser::query()->create([
            'username' => 'ticket-view-limited',
            'email' => 'ticket-view-limited@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        Role::query()->firstOrCreate(['name' => 'support-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['support-admin']);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'ticket.view', 'guard_name' => 'web']));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('当前账号没有工单操作权限。')
            ->assertDontSee('回复内容')
            ->assertDontSee('分配工单')
            ->assertDontSee('关闭工单');
    }

    public function test_admin_close_ticket_reports_failure_when_closed_status_is_missing(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.tickets.close', $ticket))
            ->assertRedirect(route('admin.tickets.show', $ticket))
            ->assertSessionHas('error', '工单关闭失败');

        $this->assertSame((int) $ticket->status_id, (int) $ticket->fresh()->status_id);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'ticket.close',
            'target_id' => $ticket->id,
            'result' => 'failed',
        ]);
    }

    public function test_ticket_service_rejects_assigning_missing_or_inactive_admin(): void
    {
        $ticket = $this->ticket();
        $activeAdmin = AdminUser::query()->create([
            'username' => 'ticket-active-assignee',
            'email' => 'ticket-active-assignee@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        $inactiveAdmin = AdminUser::query()->create([
            'username' => 'ticket-inactive-assignee',
            'email' => 'ticket-inactive-assignee@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 0,
        ]);

        $service = app(TicketService::class);

        $this->assertFalse($service->assign($ticket, 999999));
        $this->assertSame(0, (int) $ticket->fresh()->assigned_to);

        $this->assertFalse($service->assign($ticket, (int) $inactiveAdmin->id));
        $this->assertSame(0, (int) $ticket->fresh()->assigned_to);

        $this->assertTrue($service->assign($ticket, (int) $activeAdmin->id));
        $this->assertSame((int) $activeAdmin->id, (int) $ticket->fresh()->assigned_to);
    }

    public function test_ticket_service_rejects_assigning_closed_ticket_with_stale_model(): void
    {
        $ticket = $this->ticket()->fresh(['status']);
        TicketStatus::query()->create(['name' => 'Closed', 'is_default' => false]);
        $admin = AdminUser::query()->create([
            'username' => 'ticket-closed-assignee',
            'email' => 'ticket-closed-assignee@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);
        $staleTicket = $ticket->replicate();
        $staleTicket->setRawAttributes($ticket->getAttributes(), true);
        $staleTicket->exists = true;
        $staleTicket->setRelation('status', $ticket->status);

        $this->assertTrue(app(TicketService::class)->close($ticket));

        $this->assertFalse(app(TicketService::class)->assign($staleTicket, (int) $admin->id));
        $this->assertSame(0, (int) $ticket->fresh()->assigned_to);
    }

    public function test_admin_assign_ticket_rejects_missing_or_inactive_admin(): void
    {
        $ticket = $this->ticket();
        $operator = $this->admin();
        $inactiveAdmin = AdminUser::query()->create([
            'username' => 'ticket-route-inactive-assignee',
            'email' => 'ticket-route-inactive-assignee@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 0,
        ]);
        $activeAdmin = AdminUser::query()->create([
            'username' => 'ticket-route-active-assignee',
            'email' => 'ticket-route-active-assignee@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        $this->actingAs($operator, 'admin')
            ->from(route('admin.tickets.show', $ticket))
            ->post(route('admin.tickets.assign', $ticket), ['admin_id' => 999999])
            ->assertRedirect(route('admin.tickets.show', $ticket))
            ->assertSessionHasErrors('admin_id');
        $this->assertSame(0, (int) $ticket->fresh()->assigned_to);

        $this->actingAs($operator, 'admin')
            ->from(route('admin.tickets.show', $ticket))
            ->post(route('admin.tickets.assign', $ticket), ['admin_id' => $inactiveAdmin->id])
            ->assertRedirect(route('admin.tickets.show', $ticket))
            ->assertSessionHasErrors('admin_id');
        $this->assertSame(0, (int) $ticket->fresh()->assigned_to);

        $this->actingAs($operator, 'admin')
            ->post(route('admin.tickets.assign', $ticket), ['admin_id' => $activeAdmin->id])
            ->assertRedirect(route('admin.tickets.show', $ticket))
            ->assertSessionHas('status', '工单已分配');

        $this->assertSame((int) $activeAdmin->id, (int) $ticket->fresh()->assigned_to);
    }

    public function test_admin_cannot_reply_deleted_client_ticket_but_can_close_it(): void
    {
        $ticket = $this->ticket();
        $ticket->client->delete();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('已删除')
            ->assertSee('客户已删除，不能继续回复该工单。')
            ->assertDontSee('回复内容');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tickets.reply', $ticket), ['message' => '后台继续回复'])
            ->assertRedirect(route('admin.tickets.show', $ticket))
            ->assertSessionHas('error', '当前工单不允许继续回复');

        $this->assertSame(0, TicketReply::query()->where('ticket_id', $ticket->id)->count());

        TicketStatus::query()->firstOrCreate(['name' => 'Closed'], ['is_default' => false]);
        $this->actingAs($admin, 'admin')
            ->post(route('admin.tickets.close', $ticket))
            ->assertRedirect(route('admin.tickets.show', $ticket));

        $this->assertSame('Closed', $ticket->fresh('status')->status->name);
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
            'username' => 'ticket-boundary-admin-' . random_int(1000, 9999),
            'email' => 'ticket-boundary-admin-' . random_int(1000, 9999) . '@example.com',
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
