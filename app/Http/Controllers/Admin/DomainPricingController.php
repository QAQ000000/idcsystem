<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\DomainPricing;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DomainPricingController extends Controller
{
    public function index(): View
    {
        return view('admin.domain-pricings.index', [
            'pricings' => DomainPricing::query()->orderBy('tld')->paginate(50),
            'pricing' => new DomainPricing(['active' => true]),
        ]);
    }

    public function store(Request $request, AdminAuditService $audit)
    {
        $data = $this->validated($request);
        $pricing = DomainPricing::query()->create($data);
        $audit->record($request, 'domain_pricing.create', $pricing, 'success', $data);

        return redirect()->route('admin.domain-pricings.index')->with('status', 'TLD 价格已创建');
    }

    public function update(Request $request, DomainPricing $domainPricing, AdminAuditService $audit)
    {
        $data = $this->validated($request, $domainPricing);
        $domainPricing->update($data);
        $audit->record($request, 'domain_pricing.update', $domainPricing, 'success', $data);

        return redirect()->route('admin.domain-pricings.index')->with('status', 'TLD 价格已保存');
    }

    private function validated(Request $request, ?DomainPricing $pricing = null): array
    {
        $request->merge(['tld' => '.' . ltrim(strtolower(trim((string) $request->input('tld'))), '.')]);

        $data = $request->validate([
            'tld' => ['required', 'string', 'max:20', 'regex:/^\.[a-z]{2,20}$/', Rule::unique('domain_pricings', 'tld')->ignore($pricing?->id)],
            'register_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'renew_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'transfer_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['active'] = $request->boolean('active');

        return $data;
    }
}
