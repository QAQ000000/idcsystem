<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketReply;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\User\Models\Client;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    /**
     * 创建工单。
     */
    public function create(Client $client, int $departmentId, string $subject, string $message): Ticket
    {
        return DB::transaction(function () use ($client, $departmentId, $subject, $message) {
            return Ticket::create([
                'ticket_number' => $this->nextTicketNumber(),
                'client_id' => $client->id,
                'department_id' => $departmentId,
                'status_id' => $this->defaultStatusId(),
                'subject' => $subject,
                'message' => $message,
                'priority' => 'Medium',
            ]);
        });
    }

    /**
     * 回复工单。
     */
    public function reply(Ticket $ticket, string $authorType, int $authorId, string $message): TicketReply
    {
        $reply = DB::transaction(function () use ($ticket, $authorType, $authorId, $message) {
            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'author_type' => $authorType,
                'author_id' => $authorId,
                'message' => $message,
            ]);

            $statusName = $authorType === 'admin' ? 'Answered' : 'Customer Reply';
            $statusId = TicketStatus::query()->where('name', $statusName)->value('id');
            if ($statusId) {
                $ticket->update(['status_id' => $statusId]);
            }

            return $reply;
        });

        $ticket->loadMissing('client');
        if ($ticket->client) {
            app(NotificationService::class)->notifyClient($ticket->client, 'ticket_replied', [
                'client_name' => $ticket->client->username,
                'ticket_number' => $ticket->ticket_number,
                'reply_message' => $message,
            ]);
        }

        return $reply;
    }

    /**
     * 修改工单状态。
     */
    public function changeStatus(Ticket $ticket, int $statusId): bool
    {
        return $ticket->update(['status_id' => $statusId]);
    }

    /**
     * 分配工单给管理员。
     */
    public function assign(Ticket $ticket, int $adminId): bool
    {
        return $ticket->update(['assigned_to' => $adminId]);
    }

    /**
     * 关闭工单。
     */
    public function close(Ticket $ticket): bool
    {
        $statusId = TicketStatus::query()->where('name', 'Closed')->value('id')
            ?: $ticket->status_id;

        return $ticket->update(['status_id' => $statusId]);
    }

    /**
     * 评价工单。
     */
    public function rate(Ticket $ticket, int $rating): bool
    {
        if ($rating < 1 || $rating > 5) {
            return false;
        }

        return $ticket->update(['rating' => $rating]);
    }

    private function defaultStatusId(): int
    {
        return (int) (TicketStatus::query()->where('is_default', true)->value('id')
            ?: TicketStatus::query()->orderBy('sort_order')->orderBy('id')->value('id')
            ?: 1);
    }

    private function nextTicketNumber(): string
    {
        return 'TIC' . now()->format('YmdHis') . Str::upper(Str::random(4));
    }
}
