<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Events\SessionDelivered;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Record that this course delivered something (FR-021ط).
 *
 * The stop-selling guard's only input, and the only sanctioned way for billing
 * to learn it: SessionDelivered is the bridge between this context and 005/014,
 * and a query into the settlement side fails ContextIsolationTest by design.
 *
 * Queued, for the reason written on the class_sessions migration: CloseClassSession
 * fires SessionDelivered exactly once, ever, so a throw in a SYNCHRONOUS listener
 * takes the close down with it and the session is never billed at all. And when
 * the queue is the thing that fails, the missed stamp ages the course into
 * "stopped delivering" — which refuses new purchases. That is the safe
 * direction: the failure sells nothing rather than selling into a hole.
 */
class StampCourseDelivery implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function handle(SessionDelivered $event): void
    {
        $courseId = $event->session->course_id;

        if ($courseId === null) {
            return;
        }

        $deliveredAt = $event->session->ends_at ?? now();

        /*
        | Conditional, so the column only ever moves forward.
        |
        | Two deliveries queued together can be handled in either order, and a
        | retry can replay an old one long after a newer stamp landed. Writing
        | unconditionally would then walk the course's freshness BACKWARDS and
        | stop its sales over a session that has already happened.
        |
        | withoutWorkspaceScope: a queued job has no workspace context, so the
        | scope is inert here anyway — said out loud rather than relied on.
        */
        Course::query()
            ->withoutWorkspaceScope()
            ->whereKey($courseId)
            ->where(function ($query) use ($deliveredAt): void {
                $query->whereNull('last_delivered_at')
                    ->orWhere('last_delivered_at', '<', $deliveredAt);
            })
            ->update(['last_delivered_at' => $deliveredAt]);
    }
}
