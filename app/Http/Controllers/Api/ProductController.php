<?php

namespace App\Http\Controllers\Api;

use App\Modules\Finance\Services\CurrencyService;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    /**
     * 获取可购买产品列表。
     *
     * @response 200 {"success":true,"data":[{"id":1,"name":"虚拟主机","type":"hosting","prices":{"monthly":99}}]}
     */
    public function index(Request $request, ProductService $products, PricingService $pricing, CurrencyService $currencies): JsonResponse
    {
        $currency = $currencies->default();
        $items = $products->getAvailableProducts();

        if ($type = $this->queryString($request, 'type')) {
            $items = $items->where('type', $type);
        }

        $items = $items
            ->load(['group', 'pricings'])
            ->map(fn (Product $product) => $this->productPayload($product, $pricing, (int) $currency->id))
            ->values()
            ->all();

        return $this->success($items);
    }

    /**
     * 获取产品详情。
     *
     * @response 200 {"success":true,"data":{"id":1,"name":"虚拟主机","prices":{"monthly":99}}}
     */
    public function show(Product $product, PricingService $pricing, CurrencyService $currencies): JsonResponse
    {
        if (!app(ProductService::class)->isPurchasable($product)) {
            return $this->error('产品不存在或不可购买。', 404);
        }

        $product->load(['group', 'pricings']);

        return $this->success($this->productPayload($product, $pricing, (int) $currencies->default()->id));
    }

    private function productPayload(Product $product, PricingService $pricing, int $currencyId): array
    {
        $cycles = ['monthly', 'quarterly', 'semiannually', 'annually'];

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'type' => $product->type,
            'group' => $product->group ? [
                'id' => $product->group->id,
                'name' => $product->group->name,
            ] : null,
            'stock_control' => (bool) $product->stock_control,
            'stock_qty' => $product->stock_qty,
            'prices' => collect($cycles)
                ->mapWithKeys(fn (string $cycle) => [$cycle => $pricing->calculatePrice($product, $cycle, [], $currencyId)])
                ->all(),
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
