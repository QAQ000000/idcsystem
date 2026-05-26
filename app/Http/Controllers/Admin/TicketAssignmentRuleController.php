<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketDepartment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketAssignmentRuleController extends Controller
{
    public function index()
    {
        $rules = TicketAssignmentRule::query()->with('department')->latest()->paginate(20);
        $departments = TicketDepartment::query()->orderBy('sort_order')->orderBy('name')->get();
        $admins = AdminUser::query()
            ->where('status', 1)
            ->orderBy('assigned_ticket_count')
            ->orderBy('username')
            ->get();

        return view('admin.ticket-assignment-rules.index', compact('rules', 'departments', 'admins'));
    }

    public function store(Request $request)
    {
        TicketAssignmentRule::query()->create($this->validated($request));

        return redirect()->route('admin.ticket-assignment-rules.index')->with('status', '分配规则已创建');
    }

    public function update(Request $request, TicketAssignmentRule $ticketAssignmentRule)
    {
        $ticketAssignmentRule->update($this->validated($request));

        return redirect()->route('admin.ticket-assignment-rules.index')->with('status', '分配规则已更新');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer', Rule::exists('ticket_departments', 'id')],
            'strategy' => ['required', Rule::in(['round_robin', 'least_active', 'random'])],
            'admin_user_ids' => ['required', 'array', 'min:1'],
            'admin_user_ids.*' => ['integer', Rule::exists('admin_users', 'id')->where(fn ($query) => $query->where('status', 1)->whereNull('deleted_at'))],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['department_id'] = $data['department_id'] ?? null;
        $data['admin_user_ids'] = array_values(array_unique(array_map('intval', $data['admin_user_ids'])));
        $data['active'] = (bool) ($data['active'] ?? false);

        return $data;
    }
}
