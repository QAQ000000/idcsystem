<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketSla;
use App\Modules\Ticket\Models\TicketSlaLog;
use App\Services\ClientActivityService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlaService
{
    public function applyToTicket(Ticket $ticket): ?TicketSlaLog
    {
        $ticket->loadMissing('slaLog');
        if ($ticket->slaLog) {
            return $ticket->slaLog;
        }

        $sla = $this->resolveSla($ticket);
        if (!$sla) {
            return null;
        }

        $baseTime = $ticket->created_at ?: now();

        return TicketSlaLog::query()->create([
            'ticket_id' => $ticket->id,
            'sla_id' => $sla->id,
            'response_due_at' => $baseTime->copy()->addMinutes($sla->response_time_minutes),
            'resolution_due_at' => $baseTime->copy()->addMinutes($sla->resolution_time_minutes),
        ]);
    }

    public function recordFirstResponse(Ticket $ticket): void
    {
        $log = TicketSlaLog::query()->where('ticket_id', $ticket->id)->first() ?: $this->applyToTicket($ticket);
        if (!$log || $log->first_response_at) {
            return;
        }

        $now = now();
        $log->forceFill([
            'first_response_at' => $now,
            'response_breached' => $log->response_due_at ? $now->gt($log->response_due_at) : false,
        ])->save();
    }

    public function recordResolution(Ticket $ticket): void
    {
        $log = TicketSlaLog::query()->where('ticket_id', $ticket->id)->first() ?: $this->applyToTicket($ticket);
        if (!$log || $log->resolved_at) {
            return;
        }

        $now = now();
        $log->forceFill([
            'resolved_at' => $now,
            'resolution_breached' => $log->resolution_due_at ? $now->gt($log->resolution_due_at) : false,
        ])->save();
    }

    public function checkBreaches(): int
    {
        $count = 0;
        $now = now();

        TicketSlaLog::query()
            ->with('ticket.client')
            ->where(function ($query) use ($now): void {
                $query->where(function ($query) use ($now): void {
                    $query->whereNull('first_response_at')
                        ->where('response_breached', false)
                        ->whereNotNull('response_due_at')
                        ->where('response_due_at', '<', $now);
                })->orWhere(function ($query) use ($now): void {
                    $query->whereNull('resolved_at')
                        ->where('resolution_breached', false)
                        ->whereNotNull('resolution_due_at')
                        ->where('resolution_due_at', '<', $now);
                });
            })
            ->chunkById(100, function ($logs) use (&$count, $now): void {
                foreach ($logs as $log) {
                    DB::transaction(function () use ($log, &$count, $now): void {
                        $locked = TicketSlaLog::query()->whereKey($log->id)->lockForUpdate()->first();
                        if (!$locked) {
                            return;
                        }

                        $updates = [];
                        if (!$locked->first_response_at && !$locked->response_breached && $locked->response_due_at && $now->gt($locked->response_due_at)) {
                            $updates['response_breached'] = true;
                        }

                        if (!$locked->resolved_at && !$locked->resolution_breached && $locked->resolution_due_at && $now->gt($locked->resolution_due_at)) {
                            $updates['resolution_breached'] = true;
                        }

                        if ($updates === []) {
                            return;
                        }

                        $locked->forceFill($updates)->save();
                        $count++;
                        $this->notifyBreach($log->ticket, array_keys($updates));
                    });
                }
            });

        return $count;
    }

    public function getStatistics(Carbon $start, Carbon $end): array
    {
        $logs = TicketSlaLog::query()
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $total = $logs->count();
        $responseTracked = $logs->whereNotNull('first_response_at')->count();
        $resolvedTracked = $logs->whereNotNull('resolved_at')->count();

        return [
            'total' => $total,
            'response_tracked' => $responseTracked,
            'resolved_tracked' => $resolvedTracked,
            'response_breaches' => $logs->where('response_breached', true)->count(),
            'resolution_breaches' => $logs->where('resolution_breached', true)->count(),
            'response_met_rate' => $responseTracked === 0 ? 0.0 : round(($responseTracked - $logs->where('response_breached', true)->count()) / $responseTracked * 100, 2),
            'resolution_met_rate' => $resolvedTracked === 0 ? 0.0 : round(($resolvedTracked - $logs->where('resolution_breached', true)->count()) / $resolvedTracked * 100, 2),
        ];
    }

    private function resolveSla(Ticket $ticket): ?TicketSla
    {
        return TicketSla::query()
            ->where('active', true)
            ->where('priority', $ticket->priority)
            ->where(function ($query) use ($ticket): void {
                $query->where('department_id', $ticket->department_id)
                    ->orWhereNull('department_id');
            })
            ->orderByRaw('department_id is null')
            ->first();
    }

    private function notifyBreach(?Ticket $ticket, array $breaches): void
    {
        if (!$ticket || !$ticket->client) {
            return;
        }

        try {
            app(ClientActivityService::class)->log($ticket->client, 'ticket.sla_breached', '工单 SLA 已超时', [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'breaches' => $breaches,
            ]);

            if ($ticket->client && !$ticket->client->trashed()) {
                app(NotificationService::class)->notifyClient($ticket->client, 'custom_email', [
                    'client_name' => $ticket->client->username,
                    'subject' => '工单 SLA 超时提醒',
                    'body' => "工单 {$ticket->ticket_number} 已触发 SLA 超时，请等待工作人员处理。",
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Ticket SLA breach notification failed without blocking workflow.', [
                'ticket_id' => $ticket->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
