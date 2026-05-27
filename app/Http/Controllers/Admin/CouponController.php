<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Coupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function index(): View
    {
        $coupons = Coupon::withCount('claims')->latest()->paginate(20);
        return view('admin.coupons.index', compact('coupons'));
    }

    public function create(): View
    {
        return view('admin.coupons.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Coupon::create($data);
        return redirect()->route('admin.coupons.index')->with('status', '优惠券创建成功');
    }

    public function edit(Coupon $coupon): View
    {
        return view('admin.coupons.edit', compact('coupon'));
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $data = $this->validated($request, $coupon);
        $coupon->update($data);
        return redirect()->route('admin.coupons.index')->with('status', '优惠券更新成功');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $coupon->delete();
        return redirect()->route('admin.coupons.index')->with('status', '优惠券已删除');
    }

    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $isPercent = $request->input('type') === 'percent';

        $rules = [
            'name'             => 'required|string|max:120',
            'description'      => 'nullable|string|max:500',
            'type'             => 'required|in:fixed,percent',
            'value'            => ['required', 'numeric', 'min:0.01', ...($isPercent ? ['max:100'] : [])],
            'min_order_amount' => 'nullable|numeric|min:0',
            'product_ids'      => 'nullable|string',
            'stock'            => 'nullable|integer|min:0',
            'starts_at'        => 'nullable|date',
            'expires_at'       => 'nullable|date|after_or_equal:starts_at',
            'is_active'        => 'boolean',
        ];

        $data = $request->validate($rules);

        if (!empty($data['product_ids'])) {
            $data['product_ids'] = array_map(
                'intval',
                array_filter(array_map('trim', explode(',', $data['product_ids'])))
            );
        } else {
            $data['product_ids'] = null;
        }

        $data['stock']            = $data['stock'] ?? 0;
        $data['min_order_amount'] = $data['min_order_amount'] ?? 0;
        $data['is_active']        = $request->boolean('is_active');

        return $data;
    }
}
