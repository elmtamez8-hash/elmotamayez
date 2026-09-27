<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Models\User;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Events\SubscriptionEnded;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionCreditHolds;
use App\Shared\Contracts\SubscriptionDirectory;
use App\Shared\Events\CourseAccessEnded;
use App\Shared\Events\CourseAccessShortened;
use App\Shared\Events\CourseAccessWithdrawn;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

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
 * course id alone would repossess them. ⛔ BUT «STILL ENROLLED» IS ONLY HALF OF
 * IT (audit 2026-09-27): the seats the subscription itself was PAYING FOR — no
 * credit hold, inside its window — are its to take whatever the enrolment says,
 * or they are charged a credit each at delivery. That arm is
 * {@see self::releaseSeatsItPaidFor()}, and it keeps any seat another live
 * subscription or a credit hold pays for.
 *
 * ⚠️ AND ONLY SESSIONS THAT HAVE NOT STARTED. A class the student sat in
 * happened: the attendance, the seat and the recording are facts, and
 * `billable_seats` is frozen at the cancellation deadline and never recomputed,
 * so reaching back moves no money and must not try to.
 *
 * ⚠️ AND NOT BY RE-ASKING ELIGIBILITY PER SEAT. A generic «release whoever is
 * no longer eligible» sweep existed (`ReleaseIneligibleBookings`, deleted
 * 2026-09-23 with no production caller) and was the wrong tool: eligibility is
 * false during ANY freeze covering the student, so a declared holiday would
 * release seats outside the freeze as well as inside it. Release on the fact
 * that happened — this subscription ended — and nothing wider.
 *
 * ⚠️ AND THREE EVENTS REACH IT, ONE FACT: access to these courses ended — a
 * subscription ran out, an order was reversed (`CourseAccessWithdrawn`), or an
 * officer expired an enrolment by hand (`CourseAccessEnded`). One release path
 * for all three, so the three cannot drift one predicate apart.
 *
 * ⚠️ AND A FOURTH EVENT ASKS THE SAME QUESTION ABOUT AN HOUR RATHER THAN A
 * COURSE. `CourseAccessShortened` — a lifted freeze took back the days it had
 * added — arrives while the enrolment is still OPEN, so «still enrolled» would
 * keep every seat. For it the test is whether the access runs to the session's
 * start (`EnrollmentDirectory::accessEndsFor()`): the seats past the new end go
 * now, the ones before it stay, and an outright purchase or a renewal already
 * re-dated behind the month still covers what it covers. Same query, same
 * `release()`; only the predicate is per seat.
 *
 * Queued and `ShouldQueueAfterCommit`: the expiry sweep claims each row
 * inside its own statement, and a worker reading before commit would find the
 * enrolment still active and release nothing.
 */
class ReleaseSeatsOnSubscriptionEnd implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly CancelBooking $bookings,
        private readonly EnrollmentDirectory $enrollments,
        private readonly SessionCreditHolds $holds,
        private readonly SubscriptionDirectory $subscriptions,
    ) {}

    public function handle(SubscriptionEnded|CourseAccessShortened|CourseAccessWithdrawn|CourseAccessEnded $event): void
    {
        if ($event instanceof SubscriptionEnded) {
            $this->releaseSeatsItPaidFor($event);
        }

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
        $shortened = $event instanceof CourseAccessShortened;
        $stillEnrolled = $shortened ? [] : $this->enrollments->activeCourseIdsFor($student);
        $accessEnds = $shortened ? $this->enrollments->accessEndsFor($student, $event->courseIds) : [];

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

            if ($shortened) {
                /*
                | ⚠️ `array_key_exists`, NEVER `??`. Null here is the OPEN-ENDED
                | answer — an outright purchase — and `$ends[$id] ?? …` reads a
                | null value as a missing key: the one enrolment that covers every
                | hour would have released every seat.
                */
                if (array_key_exists((int) $courseId, $accessEnds)) {
                    $until = $accessEnds[(int) $courseId];

                    // Covered at that hour — open-ended, or running to it at least.
                    if ($until === null || $booking->classSession->starts_at->lessThanOrEqualTo($until)) {
                        continue;
                    }
                }

                $this->bookings->release($booking, 'انتهى اشتراكك قبل موعد هذه الحصة.');

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

    /**
     * The future seats the ended subscription was PAYING FOR, whatever else the
     * student holds in the course (audit 2026-09-27).
     *
     * ⛔ THE ENROLMENT IS THE WRONG QUESTION FOR THESE SEATS. A student who
     * bought the course outright AND subscribed to a group keeps the outright
     * enrolment when the subscription is cancelled — rightly — so «still
     * enrolled» kept every seat the subscription had claimed, and each was then
     * charged a credit at delivery with the floor off: six lessons, six debts,
     * the course withheld. The seat's own money is the question:
     *
     *  · inside the subscription's window (its courses, its room size, before
     *    the first instant after its last day) — anything outside it was never
     *    this subscription's to pay for;
     *  · no open credit hold — a held seat is paid for by credits and stays;
     *  · no live subscription covering it AT ITS START — a renewal re-dated to
     *    today, or a second plan, still pays for it, and it stays.
     *
     * A seat with no hold that no subscription covers is exactly a seat the
     * charge would bill as uncovered, which is why it goes — through
     * `release()`, never billable, for the reasons at the top of this class.
     */
    private function releaseSeatsItPaidFor(SubscriptionEnded $event): void
    {
        if ($event->coveredCourseIds === [] || $event->sessionType === null || $event->coveredBefore === null) {
            return;
        }

        $bookings = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $event->studentUserId)
            ->where('status', BookingStatus::Booked)
            ->whereHas('classSession', fn ($query) => $query
                ->where('workspace_id', $event->workspaceId)
                ->whereIn('course_id', $event->coveredCourseIds)
                ->where('type', $event->sessionType)
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', CarbonImmutable::parse($event->coveredBefore)->utc()))
            ->with('classSession')
            ->get();

        if ($bookings->isEmpty()) {
            return;
        }

        $held = $this->holds->openHoldSessionIds(
            $event->studentUserId,
            array_values(array_map(
                static fn (mixed $id): int => (int) $id,
                $bookings->pluck('class_session_id')->all(),
            )),
        );

        foreach ($bookings as $booking) {
            $session = $booking->classSession;

            if ($session === null || $session->course_id === null) {
                continue;
            }

            if (in_array((int) $session->getKey(), $held, true)) {
                continue;
            }

            // Asked per seat on purpose: the answer is moment-bound (a renewal
            // starting next week covers the seats after it and not before).
            $stillCovered = $this->subscriptions->subscriberIdsAmong(
                [$event->studentUserId],
                (int) $session->course_id,
                $session->type->value,
                $session->starts_at,
            );

            if ($stillCovered !== []) {
                continue;
            }

            $this->bookings->release($booking, 'انتهى اشتراكك قبل موعد هذه الحصة.');
        }
    }
}
