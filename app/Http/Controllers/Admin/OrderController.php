<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with('client')
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20);

        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load(['client', 'hosts.product', 'invoice.items']);

        return view('admin.orders.show', compact('order'));
    }

    public function approve(Request $request, Order $order, OrderService $orders)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:50'],
            'trans_id' => ['nullable', 'string', 'max:100'],
        ]);

        $orders->markAsPaid($order, $data['payment_method'] ?? 'manual', $data['trans_id'] ?? 'ADMIN-' . $order->id);

        return redirect()->route('admin.orders.show', $order)->with('status', '订单已审核并标记支付');
    }

    public function cancel(Request $request, Order $order, OrderService $orders)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $orders->cancel($order, $data['reason'] ?? '后台取消');

        return redirect()->route('admin.orders.show', $order)->with('status', '订单已取消');
    }
}
