<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductAddon;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductAddonController extends Controller
{
    public function index(Product $product): View
    {
        return view('admin.product-addons.index', [
            'product' => $product,
            'addons' => $product->addons()->orderBy('sort_order')->orderBy('id')->paginate(20),
        ]);
    }

    public function store(Request $request, Product $product, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $addon = $product->addons()->create($data);
        $audit->record($request, 'product_addon.create', $addon, 'success', $data);

        return redirect()->route('admin.products.addons.index', $product)->with('status', '附加项已创建');
    }

    public function update(Request $request, Product $product, ProductAddon $addon, AdminAuditService $audit): RedirectResponse
    {
        abort_unless((int) $addon->product_id === (int) $product->id, 404);
        $data = $this->validated($request);
        $addon->update($data);
        $audit->record($request, 'product_addon.update', $addon, 'success', $data);

        return redirect()->route('admin.products.addons.index', $product)->with('status', '附加项已保存');
    }

    public function destroy(Request $request, Product $product, ProductAddon $addon, AdminAuditService $audit): RedirectResponse
    {
        abort_unless((int) $addon->product_id === (int) $product->id, 404);
        $addonId = $addon->id;
        $addon->delete();
        $audit->record($request, 'product_addon.delete', null, 'success', ['product_addon_id' => $addonId]);

        return redirect()->route('admin.products.addons.index', $product)->with('status', '附加项已删除');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'billing_cycle' => ['required', Rule::in(['one_time', 'recurring'])],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'stock_qty' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
