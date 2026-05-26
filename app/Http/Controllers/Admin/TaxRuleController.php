<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\TaxRule;
use App\Modules\Finance\Services\TaxService;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaxRuleController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'country_code' => $this->queryString($request, 'country_code'),
            'status' => $this->queryString($request, 'status'),
        ];

        $taxRules = TaxRule::query()
            ->when($filters['country_code'], fn ($query, string $code) => $query->where('country_code', strtoupper($code)))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('country_code')
            ->orderBy('state_code')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.tax-rules.index', compact('taxRules', 'filters'));
    }

    public function create(): View
    {
        return view('admin.tax-rules.create', [
            'taxRule' => new TaxRule(['active' => true]),
        ]);
    }

    public function store(Request $request, TaxService $taxes, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $taxes);
        $taxRule = TaxRule::query()->create($data);

        $audit->record($request, 'tax_rule.create', $taxRule, 'success', $data);

        return redirect()->route('admin.tax-rules.index')->with('status', '税率规则已创建');
    }

    public function edit(TaxRule $taxRule): View
    {
        return view('admin.tax-rules.edit', compact('taxRule'));
    }

    public function update(Request $request, TaxRule $taxRule, TaxService $taxes, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $taxes);
        $taxRule->update($data);

        $audit->record($request, 'tax_rule.update', $taxRule, 'success', $data);

        return redirect()->route('admin.tax-rules.index')->with('status', '税率规则已保存');
    }

    public function destroy(Request $request, TaxRule $taxRule, AdminAuditService $audit): RedirectResponse
    {
        $taxRuleId = $taxRule->id;
        $taxRule->delete();
        $audit->record($request, 'tax_rule.delete', null, 'success', [
            'tax_rule_id' => $taxRuleId,
        ]);

        return redirect()->route('admin.tax-rules.index')->with('status', '税率规则已删除');
    }

    private function validated(Request $request, TaxService $taxes): array
    {
        $request->merge([
            'country_code' => strtoupper(trim((string) $request->input('country_code'))),
            'state_code' => strtoupper(trim((string) $request->input('state_code'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'state_code' => ['nullable', 'string', 'regex:/^[A-Z0-9_-]{1,10}$/'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $countryCode = $taxes->normalizeCountryCode($data['country_code']);
        $stateCode = ($data['state_code'] ?? '') === ''
            ? null
            : $taxes->normalizeStateCode($data['state_code']);

        return [
            'name' => trim($data['name']),
            'country_code' => $countryCode,
            'state_code' => $stateCode,
            'rate' => round((float) $data['rate'], 2),
            'active' => $request->boolean('active'),
        ];
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
