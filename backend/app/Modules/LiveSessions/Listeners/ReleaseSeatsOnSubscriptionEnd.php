<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Models\User;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Events\SubscriptionEnded;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A subscription that ended gives back the seats it was holding (027 · FR-045).
 *
 * ⚠️ NOTHING RELEASED A SEAT BEFORE THIS. `SubscriptionAccess::close()` expires
 * the enrolments and touches no booking; `CancelSubscription` reverses the money
 * and touches no booking. So an expired or cancelled subscriber kept up to a
 * month of future seats — capacity a paying subscriber could not get — and worse:
 * `ChargeSessionSeats` reads seat holders by STATUS ALONE, finds no live
 * subscription covering them at delivery, and debits a credit for each one, past
 * the floor. The student ends deep in the negative, is withheld, and is blocked
 * from booking anything in that course — for sessions they were locked out of and
 * never asked for. `seat_holders` and `billable_seats` agree perfectly the whole
 * way, so nothing reports it.
 *
 * ⚠️ THROUGH `release()`, NEVER `handle()`. A cancellation is the student's
 * decision; this is not theirs. Beyond the record it is what makes the seat
 * recoverable: the automatic booker skips a cancelled row on purpose (FR-044), so
 * a student who lapses and then renews would never be booked into those sessions
 * again — paid for, unbooked, and silent.
 *
 * ⚠️ AND ONLY WHERE THE STUDENT NO LONGER HAS AN ENROLMENT. A subscription may
 * cover a course the student ALSO bought outright — `SubscriptionAccess` leaves
 * that enrolment alone by design («access somebody paid for once and for good»)
 * — and their seats there are not this subscription's to take. Releasing by
 * course id alone would repossess them.
 *
 * ⚠️ AND ONLY SESSIONS THAT HAVE NOT STARTED. A class the student sat in
 * happened: the attendance, the seat and the recording are facts, and
 * `billable_seats` is frozen at the cancellation deadline and never recomputed,
 * so reaching back moves no money and must not try to.
 *
 * ⚠️ AND NOT THROUGH `ReleaseIneligibleBookings`, WHICH IS THE OBVIOUS CHOICE
 * AND THE WRONG ONE. That Action releases on `BookingEligibility::allows()`,
 * which is false during ANY freeze period covering the student — so declaring a
 * holiday would release seats outside the freeze as well as inside it. It also
 * has no production caller at all today; wiring it here would give it one by
 * accident.
 *
 * Queued and `ShouldHandleEventsAfterCommit`: the expiry sweep claims each row
 * inside its own statement, and a worker reading before commit would find the
 * enrolment still active and release nothing.
 */
class ReleaseSeatsOnSubscriptionEnd implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly CancelBooking $bookings,
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    public function handle(SubscriptionEnded $event): void
    {
        if ($event->courseIds === []) {
            return;
        }

        $student = User::query()->find($event->studentUserId);

        if ($student === null) {
            return;
        }

        /*
        | ⚠️ ASKED ONCE, NOT ONCE PER SEAT. A month of a group's sessions is a
        | dozen rows, and the answer is a property of the student and the course
        | rather than of the seat — a per-booking call would be an N+1 on a queued
        | sweep that walks every subscription that ended last night.
        */
        $stillEnrolled = $this->enrollments->activeCourseIdsFor($student);

        $bookings = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $event->studentUserId)
            ->where('status', BookingStatus::Booked)
            /*
            | The workspace is pinned from the event rather than from the context:
            | this runs on a worker, where `WorkspaceContext::id()` is null and the
            | scope adds no condition at all. The value on the event is the one
            | that was true when the subscription ended.
            */
            ->whereHas('classSession', fn ($query) => $query
                ->where('workspace_id', $event->workspaceId)
                ->whereIn('course_id', $event->courseIds)
                ->where('starts_at', '>', now()))
            ->with('classSession')
            ->get();

        foreach ($bookings as $booking) {
            $courseId = $booking->classSession?->course_id;

            if ($courseId === null) {
                continue;
            }

            // Still entitled by something else — an outright purchase, another
            // live subscription. Not this subscription's seat to take back.
            if (in_array((int) $courseId, $stillEnrolled, true)) {
                continue;
            }

            $this->bookings->release($booking, 'انتهى اشتراكك قبل موعد هذه الحصة.');
        }
    }
}
