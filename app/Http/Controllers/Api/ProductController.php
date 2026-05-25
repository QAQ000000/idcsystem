<?php

namespace App\Http\Controllers\Api;

use App\Modules\Finance\Services\CurrencyService;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use Illuminate\Http\JsonResponse;

class ProductController extends ApiController
{
    public function index(ProductService $products, PricingService $pricing, CurrencyService $currencies): JsonResponse
    {
        $currency = $currencies->default();
        $items = $products->getAvailableProducts()
            ->load(['group', 'pricings'])
            ->map(fn (Product $product) => $this->productPayload($product, $pricing, (int) $currency->id))
            ->values()
            ->all();

        return $this->success($items);
    }

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
}
