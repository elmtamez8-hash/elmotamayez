<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Models\User;
use App\Modules\Learning\Events\CohortMembershipOpened;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\ClaimSubscriptionSeats;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A student who moved to another group gives up the seats they held in the one
 * they left (FR-030), and takes the seats of the one they entered (052).
 *
 * ⚠️ THE NAME PREDATES THE SECOND HALF AND IS DELIBERATELY NOT CHANGED. Since
 * 027 this class has also RE-BOOKED, and 052 widened that arm to every active
 * member and to a first join — so it is «what happens to a person's seats when
 * their group changes», releasing being one of two answers. Renaming it would
 * churn the provider wiring and every test that names it for no behaviour;
 * `media.bunny.source_disk` is the precedent for keeping a name and writing down
 * what it now means.
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
        private readonly ClaimSubscriptionSeats $claim,
    ) {}

    public function handle(CohortMembershipOpened $event): void
    {
        /*
        | ⚠️ A FIRST JOIN CARRIES NO ORIGIN, AND ONLY THE RELEASE ARM CARES.
        | 052 made this event fire on a first join as well as a move, because
        | `rebook()` below is what seats a new member in the term their teacher
        | already published. There is nothing to give up then — a `cohort_id` of
        | null would match every session that belongs to no group at all, which
        | is the student's own private lessons and every session predating groups.
        */
        if ($event->fromCohortId === null) {
            $this->rebook($event);

            return;
        }

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
            /*
            | ⚠️ `release()`, NOT `handle()` (027 · FR-045أ). A transfer is a
            | decision about a GROUP; the student did not cancel these seats and
            | nothing here is theirs to be marked against. Two things follow from
            | the status, and both were wrong before:
            |
            |  · A transfer landing past the cancellation deadline wrote
            |    `cancelled_late` with `is_billable = true` — the system took the
            |    seat away AND charged for it.
            |  · The automatic booker skips a CANCELLED row on purpose, so a
            |    student who moved A → B → A could never be booked into A's
            |    sessions again. A released row is revivable; a cancelled one is
            |    the student's own «do not put me back», and must stay.
            */
            $this->cancel->release($booking, 'انتقلت إلى مجموعة أخرى.');
        }

        $this->rebook($event);
    }

    /**
     * The other half of a transfer (027 · FR-046).
     *
     * ⚠️ RELEASING WITHOUT RE-BOOKING IS A NET LOSS OF SEATS, AND THE STUDENT DID
     * NOT ASK FOR EITHER. A teacher moves them from Saturday's group to Sunday's;
     * the loop above frees Saturday's lessons and, on its own, nothing puts them
     * into Sunday's. They end the day with fewer seats than they woke up with, in
     * a group they were moved into, with no message anywhere saying so — and they
     * paid for the month.
     *
     * ⚠️ AND THE SUBSCRIPTION IS ASKED PER LESSON, NOT ONCE. This event carries no
     * end date — a membership move says nothing about what was bought — so the
     * question is «is their month live at THIS lesson's hour», which is exactly
     * what the directory answers.
     *
     * ⚠️ THE SENTENCE THAT STOOD HERE — «a student with no subscription matches
     * nothing and is booked into nothing, which is correct: their seats are
     * theirs to take by hand» — IS REPEALED BY 052. It was the whole feature for
     * the majority of students, who pay by credit, and it meant joining a group
     * put nothing on their timetable at all. `forMemberInCohort()` now seats them
     * through the same door their own «احجز» button uses.
     *
     * Refusals are deliberately not announced here. The seats being released a
     * few lines above are the same student's, in the same breath, by the same
     * decision — a «تعذّر حجز مقعدك» arriving alongside would read as a fault in
     * a move their teacher made on purpose.
     */
    private function rebook(CohortMembershipOpened $event): void
    {
        $student = User::query()->find($event->studentUserId);

        if ($student === null) {
            return;
        }

        $this->claim->forMemberInCohort(
            $event->workspaceId,
            $student,
            $event->courseId,
            $event->toCohortId,
        );
    }
}
