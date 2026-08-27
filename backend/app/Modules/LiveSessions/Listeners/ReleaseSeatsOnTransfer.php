<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\Learning\Events\CohortMembershipOpened;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A student who moved to another group gives up the seats they held in the one
 * they left (FR-030).
 *
 * ⚠️ THROUGH `CancelBooking`, NEVER A RAW DELETE. A cancellation frees the seat,
 * settles what it costs and leaves the row where it was; deleting one breaks
 * `ReconcileCreditBalancesJob`'s standing invariant — "one consumption entry per
 * seat of a charged session" — permanently, with a cause nobody will ever find.
 *
 * ⚠️ AND ONLY ON SESSIONS THAT HAVE NOT STARTED. A class the student sat in
 * happened: their attendance, their seat and the recording it produced are
 * things that already occurred, and FR-025د says a timetable decision may not
 * reach back and take one away. `billable_seats` is likewise written ONCE at the
 * cancellation deadline and never recomputed, so a transfer after that moment
 * moves no money — and must not try to.
 *
 * Queued with `ShouldHandleEventsAfterCommit`: the membership move is a
 * transaction, and a listener that ran inside it would cancel seats against a
 * transfer that had not committed yet.
 */
class ReleaseSeatsOnTransfer implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly CancelBooking $cancel,
    ) {}

    public function handle(CohortMembershipOpened $event): void
    {
        $bookings = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $event->studentUserId)
            ->where('status', BookingStatus::Booked)
            /*
             | ⚠️ THE SCOPE IS DROPPED ON THE OUTER QUERY AND CANNOT BE DROPPED
             | INSIDE THE RELATION — `whereHas` hands a base builder, and the
             | global scope on `class_sessions` is applied by the RELATION rather
             | than by this closure. It is inert here anyway: this runs on a
             | worker, where the context is null and `WorkspaceScope` adds no
             | condition. The workspace is pinned by the event instead, which is
             | the value that was true when the transfer happened.
             */
            ->whereHas('classSession', fn ($query) => $query
                ->where('workspace_id', $event->workspaceId)
                ->where('cohort_id', $event->fromCohortId)
                ->where('starts_at', '>', now()))
            ->with('classSession')
            ->get();

        foreach ($bookings as $booking) {
            $this->cancel->handle($booking, 'انتقلت إلى مجموعة أخرى.');
        }
    }
}
