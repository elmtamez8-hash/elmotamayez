<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student's access to these courses was withdrawn, for good — today only by a
 * reversed course order (owner decision 2026-09-23: the future seats and the
 * group place go with the content).
 *
 * ⚠️ SHARED, NOT A PAYMENTS EVENT. `ContextIsolationTest` lets LiveSessions name
 * exactly one billing event and Learning none: the lower layers must not react
 * to what a student PAID. This says nothing about money — no order, no amount,
 * no reason — only that access ended, which both layers are entitled to know.
 *
 * The same three fields as `Payments\Events\SubscriptionEnded`, so
 * `ReleaseSeatsOnSubscriptionEnd` takes both. It is its own event because the
 * GROUP place is taken back here and not when a subscription ends — a renewal
 * must not lose its place in the group.
 */
class CourseAccessWithdrawn
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $courseIds  the courses whose enrolments the reversal just closed
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $studentUserId,
        public readonly array $courseIds,
        public readonly int $reversedByUserId,
    ) {}
}
