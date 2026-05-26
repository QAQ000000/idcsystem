<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketReply;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\User\Models\Client;
use App\Services\ClientActivityService;
use App\Services\Concerns\NotifiesClientsSafely;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    use NotifiesClientsSafely;

    /**
     * 创建工单。
     */
    public function create(Client $client, int $departmentId, string $subject, string $message): Ticket
    {
        if (!$this->canClientUseTicket($client)) {
            throw new \RuntimeException('客户账号状态不允许创建工单。');
        }

        if (!$this->canClientOpenDepartment($departmentId)) {
            throw new \RuntimeException('当前部门不允许客户创建工单。');
        }

        $ticket = DB::transaction(function () use ($client, $departmentId, $subject, $message) {
            $ticket = Ticket::create([
                'ticket_number' => $this->nextTicketNumber(),
                'client_id' => $client->id,
                'department_id' => $departmentId,
                'status_id' => $this->defaultStatusId(),
                'subject' => $subject,
                'message' => $message,
                'priority' => 'Medium',
            ]);

            return $ticket;
        });

        app(ClientActivityService::class)->log($client, 'ticket.created', '工单已创建', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'department_id' => $ticket->department_id,
            'subject' => $ticket->subject,
        ]);

        return $ticket;
    }

    /**
     * 回复工单。
     */
    public function reply(Ticket $ticket, string $authorType, int $authorId, string $message, array $attachments = []): ?TicketReply
    {
        $ticket->loadMissing('client');
        if (!$ticket->client || !$this->canClientUseTicket($ticket->client)) {
            return null;
        }

        $reply = DB::transaction(function () use ($ticket, $authorType, $authorId, $message, $attachments) {
            $lockedTicket = Ticket::query()
                ->with(['client', 'status'])
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->first();
            if (!$lockedTicket || !$lockedTicket->client || !$this->canClientUseTicket($lockedTicket->client)) {
                return null;
            }

            if ($this->isClosed($lockedTicket)) {
                return null;
            }

            $reply = TicketReply::create([
                'ticket_id' => $lockedTicket->id,
                'author_type' => $authorType,
                'author_id' => $authorId,
                'message' => $message,
                'attachment' => $attachments === [] ? null : $attachments,
            ]);

            $statusName = $authorType === 'admin' ? 'Answered' : 'Customer Reply';
            $statusId = TicketStatus::query()->where('name', $statusName)->value('id');
            if ($statusId) {
                $lockedTicket->update(['status_id' => $statusId]);
            }

            return $reply;
        });

        if ($reply && $ticket->client) {
            $this->notifyClientSafely($ticket->client, 'ticket_replied', [
                'client_name' => $ticket->client->username,
                'ticket_number' => $ticket->ticket_number,
                'reply_message' => $message,
            ], 'ticket.reply');
        }

        return $reply;
    }

    /**
     * 修改工单状态。
     */
    public function changeStatus(Ticket $ticket, int $statusId): bool
    {
        if (!TicketStatus::query()->whereKey($statusId)->exists()) {
            return false;
        }

        return DB::transaction(function () use ($ticket, $statusId) {
            $lockedTicket = $this->lockTicket($ticket);
            if (!$lockedTicket) {
                return false;
            }

            if ($this->isClosed($lockedTicket) && !$this->isClosedStatusId($statusId)) {
                return false;
            }

            return $lockedTicket->update(['status_id' => $statusId]);
        });
    }

    /**
     * 分配工单给管理员。
     */
    public function assign(Ticket $ticket, int $adminId): bool
    {
        if (!AdminUser::query()->whereKey($adminId)->where('status', 1)->exists()) {
            return false;
        }

        return DB::transaction(function () use ($ticket, $adminId) {
            $lockedTicket = $this->lockTicket($ticket);
            if (!$lockedTicket || $this->isClosed($lockedTicket)) {
                return false;
            }

            return $lockedTicket->update(['assigned_to' => $adminId]);
        });
    }

    /**
     * 关闭工单。
     */
    public function close(Ticket $ticket): bool
    {
        $statusId = TicketStatus::query()->where('name', 'Closed')->value('id');
        if (!$statusId) {
            return false;
        }

        return DB::transaction(function () use ($ticket, $statusId) {
            $lockedTicket = $this->lockTicket($ticket);
            if (!$lockedTicket) {
                return false;
            }

            if ($this->isClosed($lockedTicket)) {
                return true;
            }

            return $lockedTicket->update(['status_id' => $statusId]);
        });
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

    private function isClosed(Ticket $ticket): bool
    {
        $ticket->loadMissing('status');

        return $ticket->status?->name === 'Closed';
    }

    private function lockTicket(Ticket $ticket): ?Ticket
    {
        return Ticket::query()
            ->with('status')
            ->whereKey($ticket->id)
            ->lockForUpdate()
            ->first();
    }

    private function canClientUseTicket(Client $client): bool
    {
        return !$client->trashed() && $client->isActive();
    }

    private function canClientOpenDepartment(int $departmentId): bool
    {
        return TicketDepartment::query()
            ->whereKey($departmentId)
            ->where('allow_client_open', true)
            ->exists();
    }

    private function isClosedStatusId(int $statusId): bool
    {
        return TicketStatus::query()
            ->whereKey($statusId)
            ->where('name', 'Closed')
            ->exists();
    }

    private function defaultStatusId(): int
    {
        $statusId = (int) (TicketStatus::query()->where('is_default', true)->value('id')
            ?: TicketStatus::query()->orderBy('sort_order')->orderBy('id')->value('id')
            ?: 0);

        if ($statusId > 0) {
            return $statusId;
        }

        return (int) TicketStatus::query()->create([
            'name' => 'Open',
            'color' => '#16a34a',
            'show_client' => true,
            'is_default' => true,
            'sort_order' => 1,
        ])->id;
    }

    private function nextTicketNumber(): string
    {
        return 'TIC' . now()->format('YmdHis') . Str::upper(Str::random(4));
    }
}
