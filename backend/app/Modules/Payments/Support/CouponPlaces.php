<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Payments\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A coupon's place follows its order (owner decision 2026-10-29).
 *
 * ⛔ IT USED TO BE TAKEN AT PURCHASE AND NEVER GIVEN BACK. That was the safe
 * direction against overselling, but anyone with throwaway accounts could drain
 * a limited code with orders they never paid (QA run, 2026-10-29), and an
 * honest buyer whose receipt was refused lost their place for good.
 *
 * Now: an order that never received a receipt releases its place after 48
 * hours (`ExpireUnpaidCouponOrders`) — an officer's refusal does NOT, because
 * a refused order carried a receipt and its buyer has most likely paid. A
 * receipt uploaded onto that expired order claims a place AGAIN — through the same conditional increment
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

        /*
        | ⚠️ ONE TRANSACTION, AND THE ROW'S UPDATE IS ITS FIRST STATEMENT. On
        | MySQL that UPDATE holds the redemption row's lock until commit, so a
        | second reclaim (two uploads, an upload and an approval) WAITS on it and
        | then reads the final state — never the in-between «held» of a reclaim
        | that is about to fail. A code with no place left throws inside the
        | transaction, so the row goes back to «released» by the rollback, with
        | no moment in which anybody saw it otherwise. (Security review,
        | 2026-10-29.) Nested inside `ApproveOrder`'s own transaction it is a
        | savepoint, and its refusal rolls the approval back with it.
        */
        DB::transaction(function () use ($redemption): void {
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
                throw new DomainException(self::RECLAIM_REFUSAL);
            }
        });
    }

    private function redemptionOf(Order $order): ?CouponRedemption
    {
        return CouponRedemption::query()->where('order_id', $order->getKey())->first();
    }
}
