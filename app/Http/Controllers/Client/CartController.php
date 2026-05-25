<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\CartService;
use App\Modules\Order\Services\HostService;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function index(CartService $cart)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        return view('client.cart.index', ['cart' => $cart->getCart($client)]);
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
        ]);

        $product = Product::query()
            ->where('hidden', false)
            ->where('retired', false)
            ->findOrFail($data['product_id']);
        if (!$products->isPurchasable($product, (int) ($data['qty'] ?? 1))) {
            abort(404);
        }

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
}
