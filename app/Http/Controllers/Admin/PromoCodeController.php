<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\PromoCode;
use App\Modules\Product\Models\Product;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PromoCodeController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'code' => $this->queryString($request, 'code'),
            'status' => $this->queryString($request, 'status'),
        ];

        $promoCodes = PromoCode::query()
            ->when($filters['code'], fn ($query, string $code) => $query->where('code', 'like', "%{$code}%"))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('active', false))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.promo-codes.index', compact('promoCodes', 'filters'));
    }

    public function create(): View
    {
        return view('admin.promo-codes.create', [
            'promoCode' => new PromoCode([
                'type' => 'percentage',
                'applies_to' => 'all',
                'active' => true,
                'once_per_client' => false,
            ]),
            'products' => $this->products(),
        ]);
    }

    public function store(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $promoCode = PromoCode::query()->create($data);

        $audit->record($request, 'promo_code.create', $promoCode, 'success', $data);

        return redirect()->route('admin.promo-codes.index')->with('status', '优惠码已创建');
    }

    public function edit(PromoCode $promoCode): View
    {
        return view('admin.promo-codes.edit', [
            'promoCode' => $promoCode,
            'products' => $this->products(),
        ]);
    }

    public function update(Request $request, PromoCode $promoCode, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $promoCode);
        $promoCode->update($data);

        $audit->record($request, 'promo_code.update', $promoCode, 'success', $data);

        return redirect()->route('admin.promo-codes.index')->with('status', '优惠码已保存');
    }

    public function destroy(Request $request, PromoCode $promoCode, AdminAuditService $audit): RedirectResponse
    {
        if ((int) $promoCode->used_count > 0) {
            $promoCode->update(['active' => false]);
            $audit->record($request, 'promo_code.disable_instead_delete', $promoCode, 'success', [
                'promo_code_id' => $promoCode->id,
                'used_count' => $promoCode->used_count,
            ]);

            return redirect()->route('admin.promo-codes.index')->with('status', '优惠码已有使用记录，已改为停用');
        }

        $promoCodeId = $promoCode->id;
        $promoCode->delete();
        $audit->record($request, 'promo_code.delete', null, 'success', [
            'promo_code_id' => $promoCodeId,
        ]);

        return redirect()->route('admin.promo-codes.index')->with('status', '优惠码已删除');
    }

    public function toggle(Request $request, PromoCode $promoCode, AdminAuditService $audit): RedirectResponse
    {
        $promoCode->update(['active' => !$promoCode->active]);
        $audit->record($request, 'promo_code.toggle', $promoCode, 'success', [
            'active' => $promoCode->active,
        ]);

        return redirect()->route('admin.promo-codes.index')->with('status', $promoCode->active ? '优惠码已启用' : '优惠码已停用');
    }

    private function validated(Request $request, ?PromoCode $promoCode = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('promo_codes', 'code')->ignore($promoCode?->id)],
            'type' => ['required', Rule::in(['percentage', 'fixed'])],
            'value' => ['required', 'numeric', 'min:0.01'],
            'applies_to' => ['required', Rule::in(['all', 'products'])],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'max_uses' => ['nullable', 'integer', 'min:0'],
            'once_per_client' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'active' => ['nullable', 'boolean'],
        ]);

        if ($data['type'] === 'percentage') {
            $request->validate(['value' => ['numeric', 'max:100']]);
        }

        $data['code'] = strtoupper(trim($data['code']));
        $data['product_ids'] = $data['applies_to'] === 'products'
            ? array_values(array_unique(array_map('intval', $data['product_ids'] ?? [])))
            : null;
        $data['max_uses'] = (int) ($data['max_uses'] ?? 0);
        $data['once_per_client'] = $request->boolean('once_per_client');
        $data['active'] = $request->boolean('active');

        return $data;
    }

    private function products()
    {
        return Product::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
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
