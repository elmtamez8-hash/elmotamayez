<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student's access to these courses was ended by hand — today only by an
 * officer setting a NON-subscription enrolment to «منتهٍ» in `/admin`.
 *
 * ⚠️ WHY A THIRD EVENT AND NOT ONE OF THE TWO THAT EXIST. The panel wrote the
 * column raw, so a purchased enrolment expired by hand kept every future seat
 * the student had booked in that course — capacity nobody else could take, and
 * seats `ChargeSessionSeats` would then charge at delivery, since billing reads
 * seat holders by status alone. The release path is
 * `ReleaseSeatsOnSubscriptionEnd`, and it already takes two events:
 *
 *   · `SubscriptionEnded` is a Payments event and says something that did not
 *     happen here — no subscription ended;
 *   · `CourseAccessWithdrawn` also takes the student's GROUP place, through
 *     `LeaveCohortsOnOrderReversed`, with the reason «استُرِدَّ ثمنُ الكورس» —
 *     a refund that did not happen either, and a place the panel's own «reopen
 *     an expired purchase by hand» would then not give back.
 *
 * So this one says only what the officer did: access ended. The seats go; the
 * group place and the money stay as they are.
 *
 * The same three fields as the other two, so the listener takes all three.
 */
class CourseAccessEnded
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $courseIds  the courses whose enrolments were just closed
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $studentUserId,
        public readonly array $courseIds,
    ) {}
}
