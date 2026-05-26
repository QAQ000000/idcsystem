<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TicketAssignmentService
{
    public function autoAssign(Ticket $ticket): ?AdminUser
    {
        $rule = $this->resolveRule($ticket);
        if (!$rule) {
            return null;
        }

        $adminUserIds = $this->activeAdminUserIds($rule->admin_user_ids ?? []);
        if ($adminUserIds === []) {
            return null;
        }

        return match ($rule->strategy) {
            'round_robin' => $this->assignByRoundRobin($adminUserIds, $rule->id),
            'random' => $this->assignByRandom($adminUserIds),
            default => $this->assignByLeastActive($adminUserIds),
        };
    }

    public function assignByRoundRobin(array $adminUserIds, ?int $ruleId = null): ?AdminUser
    {
        $adminUserIds = array_values($this->activeAdminUserIds($adminUserIds));
        if ($adminUserIds === []) {
            return null;
        }

        $key = 'ticket_assignment_rule:' . ($ruleId ?: md5(implode(',', $adminUserIds))) . ':cursor';
        $cursor = Cache::increment($key);
        $adminId = $adminUserIds[($cursor - 1) % count($adminUserIds)];

        return AdminUser::query()->find($adminId);
    }

    public function assignByLeastActive(array $adminUserIds): ?AdminUser
    {
        $adminUserIds = $this->activeAdminUserIds($adminUserIds);
        if ($adminUserIds === []) {
            return null;
        }

        return AdminUser::query()
            ->whereIn('id', $adminUserIds)
            ->where('status', 1)
            ->orderBy('assigned_ticket_count')
            ->orderBy('id')
            ->first();
    }

    public function assignByRandom(array $adminUserIds): ?AdminUser
    {
        $adminUserIds = $this->activeAdminUserIds($adminUserIds);
        if ($adminUserIds === []) {
            return null;
        }

        return AdminUser::query()
            ->whereIn('id', $adminUserIds)
            ->where('status', 1)
            ->inRandomOrder()
            ->first();
    }

    public function incrementCount(AdminUser $adminUser): void
    {
        AdminUser::query()
            ->whereKey($adminUser->id)
            ->increment('assigned_ticket_count');
    }

    public function decrementCount(AdminUser $adminUser): void
    {
        AdminUser::query()
            ->whereKey($adminUser->id)
            ->where('assigned_ticket_count', '>', 0)
            ->decrement('assigned_ticket_count');
    }

    public function assignTicket(Ticket $ticket, AdminUser $adminUser): bool
    {
        return DB::transaction(function () use ($ticket, $adminUser): bool {
            $lockedTicket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();
            if (!$lockedTicket) {
                return false;
            }

            $previousAdminId = (int) $lockedTicket->assigned_to;
            if ($previousAdminId === (int) $adminUser->id) {
                return true;
            }

            $lockedTicket->update(['assigned_to' => $adminUser->id]);

            if ($previousAdminId > 0) {
                $previous = AdminUser::query()->find($previousAdminId);
                if ($previous) {
                    $this->decrementCount($previous);
                }
            }

            $this->incrementCount($adminUser);

            return true;
        });
    }

    private function resolveRule(Ticket $ticket): ?TicketAssignmentRule
    {
        return TicketAssignmentRule::query()
            ->where('active', true)
            ->where(function ($query) use ($ticket): void {
                $query->where('department_id', $ticket->department_id)
                    ->orWhereNull('department_id');
            })
            ->orderByRaw('department_id is null')
            ->first();
    }

    private function activeAdminUserIds(array $adminUserIds): array
    {
        $ids = collect($adminUserIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return AdminUser::query()
            ->whereIn('id', $ids)
            ->where('status', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
