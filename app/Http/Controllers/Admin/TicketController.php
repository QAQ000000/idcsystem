<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketService;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $keyword = $this->queryString($request, 'keyword');

        $tickets = Ticket::query()
            ->with(['client', 'department', 'status'])
            ->when($keyword, function ($query, string $keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('ticket_number', 'like', "%{$keyword}%")
                        ->orWhere('subject', 'like', "%{$keyword}%")
                        ->orWhereHas('client', function ($query) use ($keyword) {
                            $query->where('username', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });
                });
            })
            ->latest()
            ->paginate(20);

        return view('admin.tickets.index', compact('tickets'));
    }

    public function show(Ticket $ticket)
    {
        $ticket->load(['client', 'department', 'status', 'replies']);

        return view('admin.tickets.show', compact('ticket'));
    }

    public function reply(Request $request, Ticket $ticket, TicketService $tickets, AdminAuditService $audit)
    {
        $data = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $reply = $tickets->reply($ticket, 'admin', (int) $request->user('admin')->id, $data['message']);
        $audit->record($request, 'ticket.reply', $ticket, $reply ? 'success' : 'failed', [
            'reply_id' => $reply?->id,
        ], $reply ? null : '当前工单不允许继续回复');

        if (!$reply) {
            return redirect()->route('admin.tickets.show', $ticket)->with('error', '当前工单不允许继续回复');
        }

        return redirect()->route('admin.tickets.show', $ticket)->with('status', '工单已回复');
    }

    public function assign(Request $request, Ticket $ticket, TicketService $tickets, AdminAuditService $audit)
    {
        $data = $request->validate([
            'admin_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('admin_users', 'id')->where(fn ($query) => $query->where('status', 1)->whereNull('deleted_at')),
            ],
        ]);

        $success = $tickets->assign($ticket, (int) $data['admin_id']);
        $audit->record($request, 'ticket.assign', $ticket, $success ? 'success' : 'failed', [
            'admin_id' => (int) $data['admin_id'],
        ], $success ? null : '工单分配失败');

        if (!$success) {
            return redirect()->route('admin.tickets.show', $ticket)->with('error', '工单分配失败');
        }

        return redirect()->route('admin.tickets.show', $ticket)->with('status', '工单已分配');
    }

    public function close(Request $request, Ticket $ticket, TicketService $tickets, AdminAuditService $audit)
    {
        $success = $tickets->close($ticket);
        $audit->record($request, 'ticket.close', $ticket, $success ? 'success' : 'failed', [], $success ? null : '工单关闭失败');

        if (!$success) {
            return redirect()->route('admin.tickets.show', $ticket)->with('error', '工单关闭失败');
        }

        return redirect()->route('admin.tickets.show', $ticket)->with('status', '工单已关闭');
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
