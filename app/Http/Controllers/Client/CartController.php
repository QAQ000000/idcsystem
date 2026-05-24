<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\CartService;
use App\Modules\Product\Models\Product;
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

    public function add(Request $request, CartService $cart)
    {
        $client = Auth::guard('client')->user();

        if (!$client) {
            return redirect()->route('client.login');
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['nullable', 'string', 'max:50'],
            'qty' => ['nullable', 'integer', 'min:1'],
        ]);

        $cart->add($client, Product::query()->findOrFail($data['product_id']), $data);

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

        $order = $cart->checkout($client);

        return redirect()->route('client.invoices.show', $order->invoice_id)->with('status', '订单已创建');
    }
}
