<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\CurrencyService;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\PricingService;
use App\Modules\Product\Services\ProductService;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index(ProductService $products, PricingService $pricing, CurrencyService $currencies)
    {
        $currency = $this->displayCurrency($currencies);
        $availableProducts = $products->getAvailableProducts();

        return view('client.products.index', [
            'products' => $availableProducts,
            'currency' => $currency,
            'monthlyPrices' => $availableProducts
                ->mapWithKeys(fn (Product $product) => [$product->id => $this->displayPrice($product, 'monthly', $currency, $pricing, $currencies)]),
        ]);
    }

    public function show(Product $product, PricingService $pricing, CurrencyService $currencies)
    {
        abort_unless(app(ProductService::class)->isPurchasable($product), 404);

        $product->load([
            'group',
            'pricings',
            'customFields' => fn ($query) => $query
                ->where('admin_only', false)
                ->orderBy('sort_order')
                ->orderBy('id'),
        ]);
        $currency = $this->displayCurrency($currencies);

        return view('client.products.show', [
            'product' => $product,
            'currency' => $currency,
            'prices' => collect(['monthly', 'quarterly', 'semiannually', 'annually'])
                ->mapWithKeys(fn (string $cycle) => [$cycle => $this->displayPrice($product, $cycle, $currency, $pricing, $currencies)]),
        ]);
    }

    private function displayCurrency(CurrencyService $currencies)
    {
        $client = Auth::guard('client')->user();

        return $client?->currency_id
            ? $currencies->all()->firstWhere('id', (int) $client->currency_id) ?? $currencies->default()
            : $currencies->default();
    }

    private function displayPrice(
        Product $product,
        string $cycle,
        $currency,
        PricingService $pricing,
        CurrencyService $currencies
    ): array {
        $direct = $pricing->calculatePrice($product, $cycle, [], (int) $currency->id);
        if ($direct > 0) {
            return [
                'amount' => $direct,
                'formatted' => $currencies->format($direct, $currency),
                'approximate' => false,
            ];
        }

        $defaultCurrency = $currencies->default();
        $defaultPrice = $pricing->calculatePrice($product, $cycle, [], (int) $defaultCurrency->id);
        if ($defaultPrice <= 0 || (int) $defaultCurrency->id === (int) $currency->id) {
            return [
                'amount' => 0.0,
                'formatted' => '-',
                'approximate' => false,
            ];
        }

        $converted = $currencies->convert($defaultPrice, $defaultCurrency, $currency);

        return [
            'amount' => $converted,
            'formatted' => '约 ' . $currencies->format($converted, $currency),
            'approximate' => true,
        ];
    }
}
