<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Payments\Models\Order;
use DomainException;

/**
 * A coupon's place follows its order (owner decision 2026-10-29).
 *
 * ⛔ IT USED TO BE TAKEN AT PURCHASE AND NEVER GIVEN BACK. That was the safe
 * direction against overselling, but anyone with throwaway accounts could drain
 * a limited code with orders they never paid (QA run, 2026-10-29), and an
 * honest buyer whose receipt was refused lost their place for good.
 *
 * Now: a REJECTED order releases its place; a receipt uploaded onto that
 * rejected order claims a place AGAIN — through the same conditional increment
 * `RedeemCoupon` uses, so a code that ran out in between refuses the upload
 * rather than being oversold. Released → reclaimed is why the redemption row
 * carries `released_at` instead of being deleted.
 *
 * ⚠️ EACH STEP IS ONE CONDITIONAL UPDATE ON THE REDEMPTION FIRST, THEN THE
 * COUNTER — so a repeat (a second rejection, a double tap) finds nothing to
 * flip and moves no counter. A crash between the two leaves the counter HIGH,
 * the undersell direction, never the oversell one.
 */
class CouponPlaces
{
    public const RECLAIM_REFUSAL = 'انتهى كود الخصم قبل أن ترفع إيصالك، فلا يمكن إكمال هذا الطلب بالسعر المخفَّض. اطلب من جديد بالسعر الحالي.';

    /** Give the order's place back. Nothing to do when it holds none. */
    public function release(Order $order): void
    {
        $redemption = $this->redemptionOf($order);

        if ($redemption === null) {
            return;
        }

        $released = CouponRedemption::query()
            ->whereKey($redemption->getKey())
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        if ($released !== 1) {
            return;
        }

        Coupon::query()
            ->whereKey($redemption->coupon_id)
            ->where('redemptions_count', '>', 0)
            ->decrement('redemptions_count');
    }

    /**
     * Take the place back for an order being answered again.
     *
     * @throws DomainException when the code has no place left
     */
    public function reclaim(Order $order): void
    {
        $redemption = $this->redemptionOf($order);

        if ($redemption === null) {
            return;
        }

        // The ROW first: of two reclaims racing (two uploads, an upload and an
        // approval), exactly one flips it, and only that one moves the counter.
        $taken = CouponRedemption::query()
            ->whereKey($redemption->getKey())
            ->whereNotNull('released_at')
            ->update(['released_at' => null]);

        if ($taken !== 1) {
            return;
        }

        $claimed = Coupon::query()
            ->whereKey($redemption->coupon_id)
            ->where(fn ($query) => $query
                ->whereNull('max_redemptions')
                ->orWhereRaw('redemptions_count < max_redemptions'))
            ->increment('redemptions_count');

        if ($claimed === 0) {
            // No place left: the row goes back to «released», as it was.
            CouponRedemption::query()->whereKey($redemption->getKey())->update(['released_at' => now()]);

            throw new DomainException(self::RECLAIM_REFUSAL);
        }
    }

    private function redemptionOf(Order $order): ?CouponRedemption
    {
        return CouponRedemption::query()->where('order_id', $order->getKey())->first();
    }
}
