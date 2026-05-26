<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\Ticket\Models\TicketSla;
use App\Modules\Ticket\Services\SlaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketSlaController extends Controller
{
    public function index()
    {
        $slas = TicketSla::query()->with('department')->latest()->paginate(20);
        $departments = TicketDepartment::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.ticket-slas.index', compact('slas', 'departments'));
    }

    public function store(Request $request)
    {
        TicketSla::query()->create($this->validated($request));

        return redirect()->route('admin.ticket-slas.index')->with('status', 'SLA 规则已创建');
    }

    public function update(Request $request, TicketSla $ticketSla)
    {
        $ticketSla->update($this->validated($request));

        return redirect()->route('admin.ticket-slas.index')->with('status', 'SLA 规则已更新');
    }

    public function statistics(Request $request, SlaService $slaService)
    {
        $start = now()->subDays(30)->startOfDay();
        $end = now()->endOfDay();
        $statistics = $slaService->getStatistics($start, $end);

        return view('admin.ticket-slas.statistics', compact('statistics', 'start', 'end'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer', Rule::exists('ticket_departments', 'id')],
            'priority' => ['required', Rule::in(['Low', 'Medium', 'High', 'Urgent'])],
            'response_time_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'resolution_time_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['department_id'] = $data['department_id'] ?? null;
        $data['active'] = (bool) ($data['active'] ?? false);

        return $data;
    }
}
