<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student's access to these courses still runs, but now ends SOONER than it
 * did — today only because a freeze that had extended a subscription was lifted
 * or shortened (owner decision 2026-09-25, option 3a).
 *
 * Heard by `ReleaseSeatsOnSubscriptionEnd`, which releases the seats whose HOUR
 * the access no longer reaches. It cannot be `SubscriptionEnded`: that one means
 * the enrolments are closed, and here they are still open — only `expires_at`
 * moved — so «is the student still enrolled» answers yes and releases nothing.
 *
 * ⚠️ SHARED, NOT A PAYMENTS EVENT. `ContextIsolationTest` lets LiveSessions name
 * exactly one billing event (`SubscriptionEnded`); a course's access moving is a
 * fact about access, like `CourseAccessEnded` beside it, and carries nothing
 * about money.
 *
 * The same three fields as its two siblings, so the listener takes all four.
 */
class CourseAccessShortened
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $courseIds  the courses whose access end moved earlier
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $studentUserId,
        public readonly array $courseIds,
    ) {}
}
