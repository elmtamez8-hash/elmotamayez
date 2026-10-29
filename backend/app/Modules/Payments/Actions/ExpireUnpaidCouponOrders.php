<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\CouponPlaces;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * An order holding a coupon place, with NO receipt 48 hours after it was placed,
 * is rejected by the platform and its place goes back (owner decision
 * 2026-10-29). Without this, an order nobody ever paid held a limited code's
 * place until an officer happened to reject it by hand.
 *
 * ⚠️ NARROW ON PURPOSE:
 * - `pending` only — `under_review` carries a receipt an officer must read;
 * - `provider = manual` — a gateway order waits on the gateway, not a receipt;
 * - `amount_minor > 0` — a zero order is approvable with no receipt at all
 *   (`Order::scopeApprovable()`), so it is waiting on staff, not on the buyer;
 * - only orders that HOLD a place — no other pending order changes here.
 *
 * Rejected, not cancelled: the buyer can still upload a receipt onto it, which
 * claims a place again (`CouponPlaces::reclaim()`), or is refused when the
 * code ran out meanwhile. This is the ONLY release: an officer's refusal keeps
 * the place, because that order carried a receipt. The status flip is one conditional UPDATE, so an upload
 * landing in the same instant wins or loses cleanly.
 */
class ExpireUnpaidCouponOrders extends Action
{
    public const HOURS = 48;

    /** How long an officer's refusal keeps the place for a clearer receipt. */
    public const REJECTED_GRACE_DAYS = 7;

    public const REASON = 'لم يصل إيصال الدفع خلال 48 ساعة، فأُلغي حجز كود الخصم. ارفع الإيصال على هذا الطلب لإكماله ما دام الكود متاحاً.';

    public function __construct(private readonly CouponPlaces $couponPlaces) {}

    /** @return int how many orders this run expired */
    public function handle(): int
    {
        $expired = 0;

        Order::query()->withoutWorkspaceScope()
            ->where('status', 'pending')
            ->where('provider', 'manual')
            ->where('amount_minor', '>', 0)
            ->where('created_at', '<=', now()->subHours(self::HOURS))
            ->whereIn('id', CouponRedemption::query()->whereNull('released_at')->select('order_id'))
            ->chunkById(200, function ($orders) use (&$expired): void {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, &$expired): void {
                        $claimed = Order::query()->withoutWorkspaceScope()
                            ->whereKey($order->getKey())
                            ->where('status', 'pending')
                            ->update(['status' => 'rejected', 'rejection_reason' => self::REASON]);

                        if ($claimed === 1) {
                            $this->couponPlaces->release($order);
                            $expired++;
                        }
                    });
                }
            });

        return $expired + $this->releaseStaleRejections();
    }

    /**
     * ⛔ AN OFFICER'S REFUSAL KEEPS THE PLACE FOR SEVEN DAYS, NOT FOR EVER
     * (owner decision 2026-10-29). A refused order carried a receipt, so its
     * buyer most likely paid and gets a week to send a clearer one at the
     * discount. Held for ever, the place became the drain again through fake
     * receipts: every throwaway account's refused order kept one.
     *
     * The order stays `rejected`; only its place goes back. A receipt uploaded
     * afterwards reclaims one if the code still has one (`CouponPlaces`), and an
     * approval reclaims it too, so an upload racing this release never yields
     * an approval on a place given away.
     *
     * ⚠️ `updated_at` is the refusal's moment: a rejected order is written
     * again only by the upload that moves it out of `rejected`. Orders the
     * 48-hour pass expired are already released and are not read here.
     */
    private function releaseStaleRejections(): int
    {
        $released = 0;

        Order::query()->withoutWorkspaceScope()
            ->where('status', 'rejected')
            ->where('updated_at', '<=', now()->subDays(self::REJECTED_GRACE_DAYS))
            ->whereIn('id', CouponRedemption::query()->whereNull('released_at')->select('order_id'))
            ->chunkById(200, function ($orders) use (&$released): void {
                foreach ($orders as $order) {
                    $this->couponPlaces->release($order);
                    $released++;
                }
            });

        return $released;
    }
}
