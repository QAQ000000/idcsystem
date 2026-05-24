<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $tickets = Ticket::query()->with(['department', 'status'])->where('client_id', $client->id)->latest()->paginate(20);

        return view('client.tickets.index', compact('tickets'));
    }

    public function create()
    {
        if (!Auth::guard('client')->user()) {
            return redirect()->route('client.login');
        }

        return view('client.tickets.create', [
            'departments' => TicketDepartment::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, TicketService $tickets)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:ticket_departments,id'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        $ticket = $tickets->create($client, (int) $data['department_id'], $data['subject'], $data['message']);

        return redirect()->route('client.tickets.show', $ticket)->with('status', '工单已创建');
    }

    public function show(Ticket $ticket)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $ticket->client_id === (int) $client->id, 403);
        $ticket->load(['department', 'status', 'replies']);

        return view('client.tickets.show', compact('ticket'));
    }

    public function reply(Request $request, Ticket $ticket, TicketService $tickets)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        abort_unless((int) $ticket->client_id === (int) $client->id, 403);
        $data = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $tickets->reply($ticket, 'client', (int) $client->id, $data['message']);

        return redirect()->route('client.tickets.show', $ticket)->with('status', '工单已回复');
    }
}
