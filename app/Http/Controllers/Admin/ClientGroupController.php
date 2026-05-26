<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\ClientGroup;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientGroupController extends Controller
{
    public function index(): View
    {
        $groups = ClientGroup::query()
            ->withCount('clients')
            ->orderBy('id')
            ->paginate(20);

        return view('admin.client-groups.index', compact('groups'));
    }

    public function create(): View
    {
        return view('admin.client-groups.create', [
            'clientGroup' => new ClientGroup([
                'discount_percent' => 0,
                'color' => '#64748b',
            ]),
        ]);
    }

    public function store(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $group = ClientGroup::query()->create($data);

        $audit->record($request, 'client_group.create', $group, 'success', $data);

        return redirect()->route('admin.client-groups.index')->with('status', '客户分组已创建');
    }

    public function edit(ClientGroup $clientGroup): View
    {
        return view('admin.client-groups.edit', compact('clientGroup'));
    }

    public function update(Request $request, ClientGroup $clientGroup, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $clientGroup);
        $clientGroup->update($data);

        $audit->record($request, 'client_group.update', $clientGroup, 'success', $data);

        return redirect()->route('admin.client-groups.index')->with('status', '客户分组已保存');
    }

    public function destroy(Request $request, ClientGroup $clientGroup, AdminAuditService $audit): RedirectResponse
    {
        if ($clientGroup->clients()->exists()) {
            return redirect()->route('admin.client-groups.index')->with('error', '该分组下仍有客户，不能删除');
        }

        $groupId = $clientGroup->id;
        $clientGroup->delete();
        $audit->record($request, 'client_group.delete', null, 'success', ['client_group_id' => $groupId]);

        return redirect()->route('admin.client-groups.index')->with('status', '客户分组已删除');
    }

    private function validated(Request $request, ?ClientGroup $clientGroup = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('client_groups', 'name')->ignore($clientGroup?->id)],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
    }
}
