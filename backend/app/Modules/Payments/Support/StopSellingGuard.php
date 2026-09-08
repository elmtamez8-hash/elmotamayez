<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Courses\Models\Course;

/**
 * A course that has stopped delivering stops selling (FR-021ط · Q-11).
 *
 * The platform is the seller and holds the money until the sessions it sold are
 * delivered. A teacher who walks away has earned nothing — SessionDelivered is
 * what turns credits into earnings — but the students who already paid are still
 * owed either sessions or a refund, and taking money BACK from a teacher is a
 * manual, human negotiation (Q-11: RecordDeduction is typed by a person). So the
 * protection worth having is the one that stops the exposure growing.
 *
 * ⚠️ NEVER-DELIVERED IS NOT STALLED. A course created this morning has a null
 * `last_delivered_at`, and refusing to sell on that basis refuses to sell every
 * course on the day it is published. The clock therefore starts at `created_at`
 * and is reset by each delivery — so a course that sat unsold and untaught for
 * two months is caught by the same rule, without a second one to keep aligned.
 */
class StopSellingGuard
{
    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * Why this course sells nothing right now, or null when it does.
     *
     * ⛔ THE STATUS CONDITION WAS MISSING ENTIRELY, ON EVERY DOOR. Nothing on the
     * credit path read `courses.status` — not this guard, not `isPartyTo`, not the
     * pricing Action, not the controller — and the column defaults to `draft`. So
     * a student who is a member of the workspace could price and buy a package on
     * a course the teacher has never released, and on an archived one.
     *
     * It lands HERE rather than in each caller because this is the class that
     * already owns "does this course sell right now", and both doors already read
     * it: the pricing Action turns a refusal into an empty list, the purchase
     * Action turns it into a sentence. One spelling, and the course picker reads
     * the same one — which is what lets the picker be exactly equal to the door
     * instead of narrower than it.
     */
    public function refusalToSell(Course $course): ?string
    {
        if (! $course->isPublished()) {
            /*
            | Deliberately the same sentence as an unreadable timestamp: whether a
            | course is a draft is the teacher's business, and a buyer who is told
            | «not published yet» learns the course exists and is being worked on.
            */
            return 'هذا الكورس غير متاح للشراء حالياً.';
        }

        $since = $course->last_delivered_at ?? $course->created_at;

        if ($since === null) {
            // No creation timestamp at all is a row nothing normal produced.
            // Refusing is the direction that cannot cost anyone money.
            return 'هذا الكورس غير متاح للشراء حالياً.';
        }

        $days = $this->settings->stopSellingAfterDays();

        if ($since->copy()->addDays($days)->isFuture()) {
            return null;
        }

        return $course->last_delivered_at === null
            ? 'لم تُقدَّم أي حصة في هذا الكورس بعد، فشراء الأرصدة عليه موقوف مؤقتاً.'
            : 'توقّف تقديم الحصص في هذا الكورس منذ مدة، فشراء الأرصدة عليه موقوف حتى تُستأنف.';
    }

    public function maySell(Course $course): bool
    {
        return $this->refusalToSell($course) === null;
    }
}
