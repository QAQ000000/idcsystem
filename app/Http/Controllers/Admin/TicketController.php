<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::query()->with(['client', 'department', 'status'])->latest()->paginate(20);

        return view('admin.tickets.index', compact('tickets'));
    }

    public function show(Ticket $ticket)
    {
        $ticket->load(['client', 'department', 'status', 'replies']);

        return view('admin.tickets.show', compact('ticket'));
    }

    public function reply(Request $request, Ticket $ticket, TicketService $tickets)
    {
        $data = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $tickets->reply($ticket, 'admin', 0, $data['message']);

        return redirect()->route('admin.tickets.show', $ticket)->with('status', '工单已回复');
    }

    public function assign(Request $request, Ticket $ticket, TicketService $tickets)
    {
        $data = $request->validate([
            'admin_id' => ['required', 'integer', 'min:1'],
        ]);

        $tickets->assign($ticket, (int) $data['admin_id']);

        return redirect()->route('admin.tickets.show', $ticket)->with('status', '工单已分配');
    }

    public function close(Ticket $ticket, TicketService $tickets)
    {
        $tickets->close($ticket);

        return redirect()->route('admin.tickets.show', $ticket)->with('status', '工单已关闭');
    }
}
