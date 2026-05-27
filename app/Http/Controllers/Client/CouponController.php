<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\CouponService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons) {}

    public function index(Request $request): View
    {
        $clientId  = $request->user('client')->id;
        $available = $this->coupons->getAvailable($clientId);
        $myClaims  = $this->coupons->getClientClaims($clientId);

        return view('client.coupons.index', compact('available', 'myClaims'));
    }

    public function claim(Request $request, int $couponId): RedirectResponse
    {
        try {
            $this->coupons->claim($couponId, $request->user('client')->id);
            return redirect()->route('client.coupons.index')->with('status', '领取成功');
        } catch (InvalidArgumentException $e) {
            return redirect()->route('client.coupons.index')->withErrors(['coupon' => $e->getMessage()]);
        }
    }
}
