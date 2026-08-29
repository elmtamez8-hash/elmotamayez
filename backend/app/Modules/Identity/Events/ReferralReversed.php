<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The subscription that completed a referral was refunded (spec 011 · FR-021 · SC-007).
 *
 * The mirror of {@see ReferralCompleted}, carrying the same three identifiers —
 * because the reversal has to find the SAME award entries, and it finds them by
 * the idempotency triplet the award was written with.
 *
 * ⚠️ IT IS FIRED ONLY BY THE STATUS FLIP THAT WON. `completed → reversed` is a
 * conditional UPDATE, so a redelivered refund matches zero rows and this never
 * leaves the building a second time.
 */
class ReferralReversed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $referralId,
        public readonly int $referrerUserId,
        public readonly int $referredUserId,
    ) {}
}
