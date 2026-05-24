<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Currency;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;

class ProductController extends Controller
{
    public function index(ProductService $products)
    {
        return view('client.products.index', [
            'products' => $products->getAvailableProducts(),
        ]);
    }

    public function show(Product $product, PricingService $pricing)
    {
        abort_unless(app(ProductService::class)->isPurchasable($product), 404);

        $product->load(['group', 'pricings']);

        $currencyId = $pricing->defaultCurrencyId();

        return view('client.products.show', [
            'product' => $product,
            'currency' => Currency::query()->find($currencyId),
            'prices' => collect(['monthly', 'quarterly', 'semiannually', 'annually'])
                ->mapWithKeys(fn (string $cycle) => [$cycle => $pricing->calculatePrice($product, $cycle, ['currency_id' => $currencyId])]),
        ]);
    }
}
