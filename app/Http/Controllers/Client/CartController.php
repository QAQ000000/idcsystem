<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\CurrencyService;
use App\Modules\Order\Services\CartService;
use App\Modules\Order\Services\HostService;
use App\Modules\Product\Models\CustomField;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductAddon;
use App\Modules\Product\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function index(CartService $cart, CurrencyService $currencies)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $currency = $client->currency_id
            ? $currencies->all()->firstWhere('id', (int) $client->currency_id) ?? $currencies->default()
            : $currencies->default();

        return view('theme::cart.index', [
            'cart' => $cart->getCart($client),
            'currency' => $currency,
            'currencies' => $currencies,
        ]);
    }

    public function add(Request $request, CartService $cart, HostService $hosts, ProductService $products)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['nullable', 'string', 'max:50', 'in:' . implode(',', $hosts->availableCycles())],
            'qty' => ['nullable', 'integer', 'min:1', 'max:' . CartService::MAX_ITEM_QUANTITY],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'exists:product_addons,id'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::query()
            ->where('hidden', false)
            ->where('retired', false)
            ->findOrFail($data['product_id']);
        if (!$products->isPurchasable($product, (int) ($data['qty'] ?? 1))) {
            abort(404);
        }
        if (!$this->addonsArePurchasable($product, $data['addons'] ?? [])) {
            return redirect()->route('client.products.show', $product)->withErrors([
                'addons' => '所选附加项不可购买或库存不足',
            ]);
        }
        $data['custom_fields'] = $this->validatedCustomFields($request, $product);

        if (!$cart->add($client, $product, $data)) {
            return redirect()->route('client.products.show', $product)->withErrors([
                'product' => '当前产品或计费周期不可购买',
            ]);
        }

        return redirect()->route('client.cart.index')->with('status', '产品已加入购物车');
    }

    public function remove(int $itemId, CartService $cart)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $cart->remove($client, $itemId);

        return redirect()->route('client.cart.index')->with('status', '购物车项目已移除');
    }

    public function checkout(CartService $cart)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        if (count($cart->getCart($client)['items'] ?? []) === 0) {
            return redirect()->route('client.cart.index')->with('status', '购物车为空，无法结算');
        }

        try {
            $order = $cart->checkout($client);
        } catch (\RuntimeException $exception) {
            return redirect()->route('client.cart.index')->withErrors([
                'checkout' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('client.invoices.show', $order->invoice_id)->with('status', '订单已创建');
    }

    public function applyPromo(Request $request, CartService $cart)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
        ]);

        try {
            $cart->applyPromoCode($client, $data['code']);
        } catch (\RuntimeException $exception) {
            return redirect()->route('client.cart.index')->withErrors([
                'promo' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('client.cart.index')->with('status', '优惠码已应用');
    }

    public function removePromo(CartService $cart)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $cart->removePromoCode($client);

        return redirect()->route('client.cart.index')->with('status', '优惠码已移除');
    }

    private function validatedCustomFields(Request $request, Product $product): array
    {
        $submitted = $request->input('custom_fields', []);
        $submitted = is_array($submitted) ? $submitted : [];
        $fields = CustomField::query()
            ->where('type', 'product')
            ->where('rel_id', $product->id)
            ->where('admin_only', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $values = [];

        foreach ($fields as $field) {
            $value = trim((string) ($submitted[$field->id] ?? ''));
            if ($field->required && $value === '') {
                throw ValidationException::withMessages([
                    'custom_fields.' . $field->id => $field->field_name . '不能为空',
                ]);
            }

            if (in_array($field->field_type, ['dropdown', 'select'], true) && $value !== '' && !in_array($value, $field->optionsList(), true)) {
                throw ValidationException::withMessages([
                    'custom_fields.' . $field->id => $field->field_name . '选项无效',
                ]);
            }

            if ($field->field_type === 'checkbox') {
                $value = $value === '1' ? '1' : '0';
            }

            if ($value !== '') {
                $values[$field->id] = $value;
            }
        }

        return $values;
    }

    private function addonsArePurchasable(Product $product, mixed $addonIds): bool
    {
        $ids = collect(is_array($addonIds) ? $addonIds : [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return true;
        }

        $count = ProductAddon::query()
            ->where('product_id', $product->id)
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('stock_qty')->orWhere('stock_qty', '>', 0);
            })
            ->whereIn('id', $ids)
            ->count();

        return $count === $ids->count();
    }
}
