<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use App\Models\Plugin;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::query()->with('group')->orderBy('sort_order')->paginate(20);

        return view('admin.products.index', compact('products'));
    }

    public function create()
    {
        return view('admin.products.create', [
            'groups' => ProductGroup::query()->orderBy('sort_order')->get(),
            'serverPlugins' => $this->serverPlugins(),
        ]);
    }

    public function store(Request $request, ProductService $products)
    {
        $product = $products->create($this->validatedProduct($request));

        return redirect()->route('admin.products.show', $product)->with('status', '产品已创建');
    }

    public function show(Product $product)
    {
        $product->load(['group', 'pricings']);

        return view('admin.products.show', compact('product'));
    }

    public function edit(Product $product)
    {
        return view('admin.products.edit', [
            'product' => $product,
            'groups' => ProductGroup::query()->orderBy('sort_order')->get(),
            'serverPlugins' => $this->serverPlugins(),
        ]);
    }

    public function update(Request $request, Product $product, ProductService $products)
    {
        $products->update($product, $this->validatedProduct($request));

        return redirect()->route('admin.products.show', $product)->with('status', '产品已更新');
    }

    public function destroy(Product $product, ProductService $products)
    {
        $products->delete($product);

        return redirect()->route('admin.products.index')->with('status', '产品已删除');
    }

    public function pricing(Product $product)
    {
        $product->load('pricings');

        return view('admin.products.pricing', [
            'product' => $product,
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->get(),
            'pricing' => $product->pricings()->where('currency_id', Currency::query()->where('is_default', true)->value('id') ?: 1)->first(),
        ]);
    }

    public function updatePricing(Request $request, Product $product, PricingService $pricing)
    {
        $data = $request->validate([
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'monthly' => ['nullable', 'numeric', 'min:-1'],
            'monthly_setup' => ['nullable', 'numeric', 'min:0'],
            'quarterly' => ['nullable', 'numeric', 'min:-1'],
            'quarterly_setup' => ['nullable', 'numeric', 'min:0'],
            'semiannually' => ['nullable', 'numeric', 'min:-1'],
            'semiannually_setup' => ['nullable', 'numeric', 'min:0'],
            'annually' => ['nullable', 'numeric', 'min:-1'],
            'annually_setup' => ['nullable', 'numeric', 'min:0'],
            'biennially' => ['nullable', 'numeric', 'min:-1'],
            'biennially_setup' => ['nullable', 'numeric', 'min:0'],
            'triennially' => ['nullable', 'numeric', 'min:-1'],
            'triennially_setup' => ['nullable', 'numeric', 'min:0'],
            'onetime' => ['nullable', 'numeric', 'min:-1'],
            'hourly' => ['nullable', 'numeric', 'min:-1'],
            'daily' => ['nullable', 'numeric', 'min:-1'],
        ]);

        $currencyId = (int) $data['currency_id'];
        unset($data['currency_id']);
        $pricing->setPricing('product', (int) $product->id, $currencyId, $data);

        return redirect()->route('admin.products.pricing', $product)->with('status', '产品价格已保存');
    }

    private function validatedProduct(Request $request): array
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:25'],
            'pay_type' => ['nullable', 'string', 'max:50'],
            'pay_method' => ['nullable', 'string', 'max:20'],
            'auto_setup' => ['nullable', 'string', 'max:20'],
            'server_type' => ['nullable', 'string', 'max:100'],
            'stock_control' => ['nullable', 'boolean'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'hidden' => ['nullable', 'boolean'],
            'retired' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        foreach (['stock_control', 'hidden', 'retired', 'is_featured'] as $field) {
            $data[$field] = $request->boolean($field);
        }

        $data['pay_type'] ??= 'recurring';
        $data['pay_method'] ??= 'prepaid';
        $data['auto_setup'] ??= 'manual';
        $data['server_type'] = $data['server_type'] ?: null;
        $data['stock_qty'] ??= 0;
        $data['sort_order'] ??= 0;

        return $data;
    }

    private function serverPlugins()
    {
        return Plugin::query()
            ->where('type', 'server')
            ->where('status', 1)
            ->orderBy('title')
            ->get();
    }
}
