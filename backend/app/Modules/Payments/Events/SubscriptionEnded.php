<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A subscription stopped covering anything — it ran out, or it was cancelled
 * (spec 027 · FR-045).
 *
 * ⚠️ ONE EVENT FOR BOTH REASONS, DELIBERATELY. `SubscriptionStatus`'s own
 * docblock insists `Expired` and `Cancelled` are two states and not one with a
 * flag, because only the second has money to reverse behind it — but from a
 * SEAT's point of view they are the same news, exactly as `SessionCancelled`
 * serves both an outright cancellation and a freeze. A listener that had to
 * branch on the reason would be answering a question about money it must not
 * see.
 *
 * ⚠️ AND IT CARRIES THE COURSES, BECAUSE BY THE TIME A LISTENER RUNS THE
 * ENROLMENTS ARE ALREADY CLOSED. `SubscriptionAccess::close()` finds them by
 * `(order_id, source = 'subscription')`; a listener re-deriving that afterwards
 * would find nothing active and release nothing — the same reason
 * `SessionCancelled` carries its seat holders rather than looking them up.
 *
 * ⚠️ AND NOTHING ABOUT MONEY TRAVELS ON IT. The one consumer is LiveSessions,
 * which may not know what a subscription cost; the amount, the plan and the
 * order's own state stay on this side of the wall.
 */
class SubscriptionEnded
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $courseIds  the courses whose enrolments this ending just closed
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $studentUserId,
        public readonly array $courseIds,
    ) {}
}
