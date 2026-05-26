<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketReply;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $tickets = Ticket::query()->with(['department', 'status'])->where('client_id', $client->id)->latest()->paginate(20);

        return view('theme::tickets.index', compact('tickets'));
    }

    public function create()
    {
        if (!Auth::guard('client')->user()) {
            return redirect()->route('client.login');
        }

        return view('theme::tickets.create', [
            'departments' => TicketDepartment::query()
                ->where('allow_client_open', true)
                ->orderBy('sort_order')
                ->get(),
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

        try {
            $ticket = $tickets->create($client, (int) $data['department_id'], $data['subject'], $data['message']);
        } catch (\RuntimeException $exception) {
            return redirect()->route('client.tickets.create')->withErrors([
                'department_id' => $exception->getMessage(),
            ]);
        }

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

        return view('theme::tickets.show', compact('ticket'));
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
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx,txt,zip'],
        ]);

        $reply = $tickets->reply($ticket, 'client', (int) $client->id, $data['message'], $this->storeAttachments($request));

        if (!$reply) {
            return redirect()->route('client.tickets.show', $ticket)->withErrors([
                'ticket' => '已关闭工单不允许继续回复',
            ]);
        }

        return redirect()->route('client.tickets.show', $ticket)->with('status', '工单已回复');
    }

    public function downloadAttachment(Ticket $ticket, TicketReply $reply, int $index): StreamedResponse
    {
        $client = Auth::guard('client')->user();
        abort_unless($client && (int) $ticket->client_id === (int) $client->id, 403);
        abort_unless((int) $reply->ticket_id === (int) $ticket->id, 404);
        $attachment = $this->resolveAttachment($reply, $index);

        return Storage::disk('local')->download($attachment['path'], $attachment['name']);
    }

    private function storeAttachments(Request $request): array
    {
        $attachments = [];
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('tickets', 'local');
            $attachments[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
        }

        return $attachments;
    }

    private function resolveAttachment(TicketReply $reply, int $index): array
    {
        $attachments = is_array($reply->attachment) ? $reply->attachment : [];
        abort_unless(array_key_exists($index, $attachments), 404);
        $attachment = $attachments[$index];
        abort_unless(is_array($attachment) && is_string($attachment['path'] ?? null), 404);
        abort_unless(Storage::disk('local')->exists($attachment['path']), 404);

        return [
            'path' => $attachment['path'],
            'name' => is_string($attachment['name'] ?? null) ? $attachment['name'] : basename($attachment['path']),
        ];
    }
}
