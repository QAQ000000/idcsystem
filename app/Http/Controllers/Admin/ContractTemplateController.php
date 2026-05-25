<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\ContractTemplate;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;

class ContractTemplateController extends Controller
{
    public function index()
    {
        return view('admin.contract-templates.index', [
            'templates' => ContractTemplate::query()->latest()->paginate(20),
        ]);
    }

    public function create()
    {
        return view('admin.contract-templates.create', [
            'template' => new ContractTemplate(['active' => true]),
        ]);
    }

    public function store(Request $request, AdminAuditService $audit)
    {
        $data = $this->validated($request);
        $template = ContractTemplate::query()->create($data);
        $audit->record($request, 'contract_template.create', $template, 'success', $data);

        return redirect()->route('admin.contract-templates.index')->with('status', '合同模板已创建');
    }

    public function edit(ContractTemplate $contractTemplate)
    {
        return view('admin.contract-templates.edit', [
            'template' => $contractTemplate,
        ]);
    }

    public function update(Request $request, ContractTemplate $contractTemplate, AdminAuditService $audit)
    {
        $data = $this->validated($request);
        $contractTemplate->update($data);
        $audit->record($request, 'contract_template.update', $contractTemplate, 'success', $data);

        return redirect()->route('admin.contract-templates.index')->with('status', '合同模板已保存');
    }

    public function destroy(Request $request, ContractTemplate $contractTemplate, AdminAuditService $audit)
    {
        $id = $contractTemplate->id;
        $contractTemplate->delete();
        $audit->record($request, 'contract_template.delete', null, 'success', [
            'contract_template_id' => $id,
        ]);

        return redirect()->route('admin.contract-templates.index')->with('status', '合同模板已删除');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['active'] = $request->boolean('active');

        return $data;
    }
}
