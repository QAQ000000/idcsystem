<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Coupon;
use App\Modules\Order\Models\CouponClaim;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CouponService
{
    public function getAvailable(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        $claimedIds = CouponClaim::where('client_id', $clientId)->pluck('coupon_id');

        return Coupon::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where(fn ($q) => $q->where('stock', 0)->orWhereColumn('claimed_count', '<', 'stock'))
            ->whereNotIn('id', $claimedIds)
            ->get();
    }

    public function claim(int $couponId, int $clientId): CouponClaim
    {
        return DB::transaction(function () use ($couponId, $clientId) {
            $coupon = Coupon::lockForUpdate()->findOrFail($couponId);

            if (!$coupon->isAvailable()) {
                throw new InvalidArgumentException('优惠券不可领取');
            }

            $exists = CouponClaim::where('coupon_id', $couponId)
                ->where('client_id', $clientId)
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException('您已领取过该优惠券');
            }

            $claim = CouponClaim::create([
                'coupon_id'  => $couponId,
                'client_id'  => $clientId,
                'claimed_at' => now(),
            ]);

            $coupon->increment('claimed_count');

            return $claim;
        });
    }

    public function getClientClaims(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return CouponClaim::with('coupon')
            ->where('client_id', $clientId)
            ->latest('claimed_at')
            ->get();
    }

    public function validateAndCalculate(int $claimId, int $clientId, float $orderAmount, ?int $productId = null): array
    {
        $claim = CouponClaim::with('coupon')
            ->where('id', $claimId)
            ->where('client_id', $clientId)
            ->firstOrFail();

        if ($claim->used_at !== null) {
            throw new InvalidArgumentException('该优惠券已使用');
        }

        $coupon = $claim->coupon;

        if (!$coupon->isAvailable()) {
            throw new InvalidArgumentException('优惠券已失效');
        }

        if ($orderAmount < (float) $coupon->min_order_amount) {
            throw new InvalidArgumentException("订单金额不满足最低使用限制（¥{$coupon->min_order_amount}）");
        }

        if ($productId !== null && $coupon->product_ids !== null && !in_array($productId, $coupon->product_ids)) {
            throw new InvalidArgumentException('该优惠券不适用于此产品');
        }

        $discount = $coupon->type === 'percent'
            ? round($orderAmount * (float) $coupon->value / 100, 2)
            : min((float) $coupon->value, $orderAmount);

        return ['discount' => $discount, 'claim' => $claim];
    }

    public function markUsed(CouponClaim $claim, int $orderId): void
    {
        $claim->update(['used_at' => now(), 'order_id' => $orderId]);
    }
}
