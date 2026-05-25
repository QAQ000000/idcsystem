<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductGroup;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use App\Models\Plugin;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function store(Request $request, ProductService $products, AdminAuditService $audit)
    {
        $data = $this->validatedProduct($request);
        $product = $products->create($data);
        $audit->record($request, 'product.create', $product, 'success', $data);

        return redirect()->route('admin.products.show', $product)->with('status', '产品已创建');
    }

    public function show(Product $product)
    {
        $product->load(['group', 'pricings', 'customFields' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);

        return view('admin.products.show', compact('product'));
    }

    public function edit(Product $product)
    {
        return view('admin.products.edit', [
            'product' => $product,
            'groups' => ProductGroup::query()->orderBy('sort_order')->get(),
            'serverPlugins' => $this->serverPlugins($product),
        ]);
    }

    public function update(Request $request, Product $product, ProductService $products, AdminAuditService $audit)
    {
        $data = $this->validatedProduct($request, $product);
        $products->update($product, $data);
        $audit->record($request, 'product.update', $product, 'success', $data);

        return redirect()->route('admin.products.show', $product)->with('status', '产品已更新');
    }

    public function destroy(Request $request, Product $product, ProductService $products, AdminAuditService $audit)
    {
        $productId = $product->id;
        $success = $products->delete($product);
        $audit->record($request, 'product.delete', $product, $success ? 'success' : 'failed', [
            'product_id' => $productId,
        ], $success ? null : '产品存在关联服务，不能删除');

        if (!$success) {
            return redirect()->route('admin.products.show', $product)->with('error', '产品存在关联服务，不能删除');
        }

        return redirect()->route('admin.products.index')->with('status', '产品已删除');
    }

    public function pricing(Request $request, Product $product)
    {
        $product->load('pricings');
        $currencies = Currency::query()->orderByDesc('is_default')->orderBy('code')->get();
        $defaultCurrencyId = (int) (Currency::query()->where('is_default', true)->value('id') ?: $currencies->first()?->id ?: 0);
        $selectedCurrencyId = $this->queryInteger($request, 'currency_id') ?: $defaultCurrencyId;

        if (!$currencies->contains('id', $selectedCurrencyId)) {
            $selectedCurrencyId = $defaultCurrencyId;
        }

        return view('admin.products.pricing', [
            'product' => $product,
            'currencies' => $currencies,
            'selectedCurrencyId' => $selectedCurrencyId,
            'pricing' => $product->pricings()->where('currency_id', $selectedCurrencyId)->first(),
        ]);
    }

    public function updatePricing(Request $request, Product $product, PricingService $pricing, AdminAuditService $audit)
    {
        $data = $request->validate([
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'monthly' => $this->priceRules(-1),
            'monthly_setup' => $this->priceRules(0),
            'quarterly' => $this->priceRules(-1),
            'quarterly_setup' => $this->priceRules(0),
            'semiannually' => $this->priceRules(-1),
            'semiannually_setup' => $this->priceRules(0),
            'annually' => $this->priceRules(-1),
            'annually_setup' => $this->priceRules(0),
            'biennially' => $this->priceRules(-1),
            'biennially_setup' => $this->priceRules(0),
            'triennially' => $this->priceRules(-1),
            'triennially_setup' => $this->priceRules(0),
            'onetime' => $this->priceRules(-1),
            'hourly' => $this->priceRules(-1),
            'daily' => $this->priceRules(-1),
        ]);

        $currencyId = (int) $data['currency_id'];
        unset($data['currency_id']);
        $pricing->setPricing('product', (int) $product->id, $currencyId, $data);
        $audit->record($request, 'product.pricing.update', $product, 'success', [
            'currency_id' => $currencyId,
            'pricing' => $data,
        ]);

        return redirect()
            ->route('admin.products.pricing', [$product, 'currency_id' => $currencyId])
            ->with('status', '产品价格已保存');
    }

    private function validatedProduct(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:product_groups,id'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:25'],
            'pay_type' => ['nullable', 'string', 'max:50'],
            'pay_method' => ['nullable', 'string', 'max:20'],
            'auto_setup' => ['nullable', 'string', 'max:20'],
            'server_type' => [
                'nullable',
                'string',
                'max:100',
                $this->serverTypeRule($product),
            ],
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

    private function priceRules(int $min): array
    {
        return ['nullable', 'numeric', 'min:' . $min, 'max:' . PricingService::MAX_PRICE_AMOUNT];
    }

    private function serverPlugins(?Product $product = null)
    {
        return Plugin::query()
            ->where('type', 'server')
            ->where(function ($query) use ($product) {
                $query->where('status', 1);

                if ($product?->server_type) {
                    $query->orWhere('name', $product->server_type);
                }
            })
            ->orderBy('title')
            ->get();
    }

    private function serverTypeRule(?Product $product)
    {
        return Rule::exists('plugins', 'name')->where(function ($query) use ($product) {
            $query->where('type', 'server')
                ->where(function ($query) use ($product) {
                    $query->where('status', 1);

                    if ($product?->server_type) {
                        $query->orWhere('name', $product->server_type);
                    }
                });
        });
    }

    private function queryInteger(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
