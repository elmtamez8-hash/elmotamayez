<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\AppliedDiscount;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Payments\Models\Order;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Record a coupon's use and take one off its ceiling (spec 011 · FR-012 · FR-015).
 *
 * ⚠️ THE ROW IS WRITTEN FIRST, THE COUNTER SECOND — `CreditLedger`'s order, and
 * for its reason. Reversed, a redelivered event has its INSERT swallowed by
 * `unique(coupon_id, order_id)` and then increments the counter a second time:
 * the coupon runs out early, `redemptions_count` disagrees with `COUNT(*)` for
 * ever, and there is nothing anywhere that notices.
 *
 * ⚠️ THE CEILING IS CLAIMED BY A CONDITIONAL UPDATE, WHICH IS BOTH THE CHECK AND
 * THE CLAIM (SC-003 · NFR-010). Never `count()` then `insert()` — that is the
 * definition of the race — and never `lockForUpdate()`, a no-op on SQLite, so a
 * test built around it passes locally and proves nothing about the MySQL it will
 * run on.
 *
 * ⚠️ AND IT IS CALLED AT PURCHASE, INSIDE THE PURCHASE'S OWN TRANSACTION. The
 * three purchase routes each take a `coupon_code` and there is no column
 * carrying one between purchase and approval, so claiming at approval time would
 * need a carrier and a `refund_due`-shaped fallback for a cap that ran out in
 * between. The price of claiming here is written down rather than fixed: an
 * order later REJECTED has consumed a place on the coupon, and nothing releases
 * it. That is the safe direction — a campaign that undersells by the number of
 * abandoned bank transfers, rather than one that oversells.
 */
class RedeemCoupon extends Action
{
    public function handle(Order $order, AppliedDiscount $discount): ?CouponRedemption
    {
        $coupon = $discount->coupon;

        if ($coupon === null || $discount->isNothing()) {
            // A family discount is not a redemption: it has no code, no ceiling
            // and nothing to record beyond the amount already on the order.
            return null;
        }

        $redemption = CouponRedemption::create([
            // Assigned from the ORDER, never left to the trait: this model
            // carries no `BelongsToWorkspace` precisely because every path that
            // reaches it is a buyer's, and a buyer's context is null.
            'workspace_id' => (int) $order->workspace_id,
            'coupon_id' => $coupon->getKey(),
            'user_id' => (int) $order->user_id,
            'order_id' => (int) $order->getKey(),
            'discount_minor' => $discount->amountMinor,
        ]);

        /*
        | The claim. `redemptions_count < max_redemptions` is evaluated by the
        | engine inside the same statement that moves it, so two buyers arriving
        | at the last place cannot both pass.
        |
        | `whereRaw` on two of this table's own columns — Eloquent has no
        | column-to-column comparison, and the alternative is reading the ceiling
        | into PHP first, which is the read-then-write this exists to avoid.
        */
        $claimed = Coupon::query()
            ->whereKey($coupon->getKey())
            ->where(fn ($query) => $query
                ->whereNull('max_redemptions')
                ->orWhereRaw('redemptions_count < max_redemptions'))
            ->increment('redemptions_count');

        if ($claimed === 0) {
            // Somebody took the last place between the resolver's read and this
            // statement. Throwing rolls the caller's transaction back — the
            // redemption row above included — so the buyer is refused rather
            // than charged against a coupon that had nothing left.
            throw new DomainException('استُنفد هذا الكود.');
        }

        return $redemption;
    }
}
