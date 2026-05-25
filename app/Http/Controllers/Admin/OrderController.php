<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->queryString($request, 'status');
        $keyword = $this->queryString($request, 'keyword');

        $orders = Order::query()
            ->with('client')
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->when($keyword, function ($query, string $keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('order_number', 'like', "%{$keyword}%")
                        ->orWhereHas('client', function ($query) use ($keyword) {
                            $query->where('username', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });
                });
            })
            ->latest()
            ->paginate(20);

        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load(['client', 'hosts.product', 'invoice.items']);

        return view('admin.orders.show', compact('order'));
    }

    public function approve(Request $request, Order $order, OrderService $orders, AdminAuditService $audit)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:50'],
            'trans_id' => ['nullable', 'string', 'max:100'],
        ]);

        if ($order->status !== 'Pending') {
            $audit->record($request, 'order.approve', $order, 'failed', [
                'order_status' => $order->status,
            ], '当前订单状态不允许标记支付');

            return redirect()->route('admin.orders.show', $order)->with('error', '当前订单状态不允许标记支付');
        }

        $success = $orders->markAsPaid($order, $data['payment_method'] ?? 'manual', $data['trans_id'] ?? 'ADMIN-ORDER-' . $order->id);
        $audit->record($request, 'order.approve', $order, $success ? 'success' : 'failed', [
            'payment_method' => $data['payment_method'] ?? 'manual',
            'trans_id' => $data['trans_id'] ?? 'ADMIN-ORDER-' . $order->id,
            'order_status' => $order->fresh()->status,
        ], $success ? null : '订单账单支付失败');

        if (!$success) {
            return redirect()->route('admin.orders.show', $order)->with('error', '订单账单支付失败');
        }

        return redirect()->route('admin.orders.show', $order)->with('status', '订单已审核并标记支付');
    }

    public function cancel(Request $request, Order $order, OrderService $orders, AdminAuditService $audit)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $success = $orders->cancel($order, $data['reason'] ?? '后台取消');
        $audit->record($request, 'order.cancel', $order, $success ? 'success' : 'failed', [
            'reason' => $data['reason'] ?? '后台取消',
            'order_status' => $order->fresh()->status,
        ], $success ? null : '当前订单状态不允许取消');

        if (!$success) {
            return redirect()->route('admin.orders.show', $order)->with('error', '当前订单状态不允许取消');
        }

        return redirect()->route('admin.orders.show', $order)->with('status', '订单已取消');
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
