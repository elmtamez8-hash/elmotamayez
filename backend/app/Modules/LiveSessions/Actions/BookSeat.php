<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a seat, or refuses.
 *
 * The guard against overbooking is one conditional UPDATE:
 *
 *     UPDATE class_sessions SET seats_taken = seats_taken + 1
 *     WHERE id = ? AND seats_taken < seats_total
 *
 * If it affected a row, the seat is ours. If it affected none, the session was
 * full at the instant we asked. There is no window between the check and the
 * write because there is no separate check — which is the whole difference
 * between this and `if (count < seats) insert`, the textbook race.
 *
 * `lockForUpdate()` would also be correct on MySQL, and was rejected for a
 * specific reason: it is a no-op on SQLite, so the test proving SC-001 would
 * pass locally while proving nothing about production. A guard we cannot test is
 * worse than one we can, even when it is theoretically stronger.
 */
class BookSeat extends Action
{
    public function __construct(
        private readonly BookingEligibility $eligibility,
        private readonly CohortDirectory $cohorts,
    ) {}

    public function handle(ClassSession $session, User $student): SessionBooking
    {
        $this->assertBookable($session);

        // ⚠️ `openingRefusal`, not `refusalReason`: booking is one of the two
        // doors FR-041 names, so 008's unlock condition is asked here too.
        return $this->claim($session, $student, $this->eligibility->openingRefusal($session, $student));
    }

    /**
     * The seat behind a private-session request the teacher has just granted
     * (023 · FR-019 · FR-020).
     *
     * ⚠️ A NAMED SECOND ENTRY POINT, NOT A BOOLEAN PARAMETER AND NOT A SECOND
     * COPY OF THE CLAIM. A flag is read backwards by the first caller written
     * after it — `handle($session, $student, true)` says nothing at its call
     * site — and a second atomic UPDATE somewhere else is the two-spellings
     * defect this repository has paid for six times. The claim below is the one
     * that runs for both doors.
     *
     * ⚠️ AND IT ASKS `refusalReason()`, NOT `openingRefusal()`. The unlock gate
     * (008 · FR-036) governs the next lesson on a course PATH; a private hour is
     * what a student asks for precisely because they are behind on it, so asking
     * it here would refuse exactly the student the feature exists for — and
     * would refuse them at the teacher's press, after the teacher already
     * decided. The money and enrolment conditions are asked, because those the
     * teacher cannot waive.
     */
    public function claimGrantedSeat(ClassSession $session, User $student): SessionBooking
    {
        $this->assertBookable($session);

        return $this->claim($session, $student, $this->eligibility->refusalReason($session, $student));
    }

    /**
     * The seat the SYSTEM took back, given back (027 · FR-045أ).
     *
     * ⚠️ A THIRD NAMED ENTRY, AND THE ONE THING IT DOES NOT DO IS INSERT. The
     * unique index on (class_session_id, student_user_id) is still there, so a
     * released row makes `claim()` throw «لديك مقعد محجوز في هذه الحصة بالفعل» —
     * a sentence that is FALSE about a seat the student does not hold, with no
     * way out from any screen. Reviving is an UPDATE on the row that is already
     * there.
     *
     * ⚠️ AND IT LIVES HERE RATHER THAN IN THE CALLER, BECAUSE THE CAPACITY CLAIM
     * DOES. `claimCapacity()` below is `private` and is the only guarded
     * increment of `seats_taken` in the tree — every other writer decrements. A
     * conditional UPDATE written inside `ClaimSubscriptionSeats` would be the
     * second spelling of the seat claim, which is the defect the docblock above
     * exists to forbid.
     *
     * ⚠️ AND ONLY `Released` IS REVIVED. A student's own cancellation stays
     * cancelled (FR-044) — a booking they undid must not come back in the night.
     * The conditional `where status = released` is what makes that true under a
     * race as well: a seat re-booked by hand a millisecond earlier affects zero
     * rows here and the claimed capacity goes straight back.
     */
    public function reviveReleasedSeat(ClassSession $session, User $student): SessionBooking
    {
        $this->assertBookable($session);

        $refusal = $this->eligibility->refusalReason($session, $student);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return DB::transaction(function () use ($session, $student): SessionBooking {
            $this->claimCapacity($session);

            $revived = SessionBooking::query()
                ->withoutWorkspaceScope()
                ->where('class_session_id', $session->getKey())
                ->where('student_user_id', $student->getKey())
                ->where('status', BookingStatus::Released->value)
                ->update([
                    'status' => BookingStatus::Booked->value,
                    'is_billable' => true,
                    'booked_at' => now(),
                    'cancelled_at' => null,
                    'cancellation_reason' => null,
                    'updated_at' => now(),
                ]);

            if ($revived === 0) {
                $this->releaseCapacity($session);

                throw new DomainException('لم يعد هذا المقعد قابلاً للاسترجاع.');
            }

            $session->refresh();

            $booking = SessionBooking::query()
                ->withoutWorkspaceScope()
                ->where('class_session_id', $session->getKey())
                ->where('student_user_id', $student->getKey())
                ->first();

            if ($booking === null) {
                // Unreachable: the UPDATE above affected exactly this row.
                throw new DomainException('تعذّر استرجاع المقعد.');
            }

            return $booking;
        });
    }

    private function assertBookable(ClassSession $session): void
    {
        if (! $session->status->acceptsBookings()) {
            throw new DomainException('هذه الحصة لم تعد متاحة للحجز.');
        }

        if ($session->starts_at->isPast()) {
            throw new DomainException('لا يمكن حجز حصة بدأت أو انتهت.');
        }
    }

    private function claim(ClassSession $session, User $student, ?string $refusal): SessionBooking
    {
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return DB::transaction(function () use ($session, $student): SessionBooking {
            $this->claimCapacity($session);

            try {
                $booking = SessionBooking::query()->create([
                    'workspace_id' => $session->workspace_id,
                    'class_session_id' => $session->getKey(),
                    'student_user_id' => $student->getKey(),
                    'status' => BookingStatus::Booked,
                    'is_billable' => true,
                    'booked_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The student already holds a seat. Give the one we just claimed
                // back, or a double-tap on the button would eat a seat nobody
                // occupies.
                $this->releaseCapacity($session);

                throw new DomainException('لديك مقعد محجوز في هذه الحصة بالفعل.');
            }

            $this->fileUnderTheStudentsOwnGroup($session, $student);

            $session->refresh();

            return $booking;
        });
    }

    /**
     * A 1:1 slot joins the student's own one-seat group the moment they take it.
     *
     * ⚠️ THE EARLIEST INSTANT THE QUESTION HAS AN ANSWER. «No session without a
     * group» is straightforward for a group lesson — it is refused at creation —
     * and impossible for an open individual slot generated from the teacher's
     * weekly availability: there is no student yet, so there is nobody to make a
     * one-seat group of. The seat is what supplies the missing half.
     *
     * ⚠️ AND THIS IS SAFE ONLY BECAUSE `coursesWithCohorts()` FILTERS `->group()`.
     * Until that was fixed, one individual cohort on a course made the course
     * «grouped» — and every session on it still carrying no group vanished from
     * every student's discovery list. Writing one here before that would have
     * turned each private booking into exactly that outage.
     *
     * ⚠️ INSIDE THE TRANSACTION, so it is the booking or neither. A stamp written
     * afterwards and failing leaves a seat whose session belongs to nobody — the
     * state this exists to abolish — with nothing that would ever notice.
     * `ensureIndividualCohort()` is idempotent by unique index, so a retry cannot
     * make a second room.
     */
    private function fileUnderTheStudentsOwnGroup(ClassSession $session, User $student): void
    {
        if ($session->type !== ClassSessionType::Individual || $session->cohort_id !== null) {
            return;
        }

        if ($session->course_id === null) {
            // A one-off with no course has no curriculum to be a group of, and
            // `ensureIndividualCohort` needs one. Historic rows only: a course
            // is required on every schedulable session since Q-7.
            return;
        }

        $cohortId = $this->cohorts->ensureIndividualCohort(
            (int) $session->course_id,
            (int) $session->workspace_id,
            $student,
            null,
        );

        // `forceFill`: `cohort_id` is deliberately not `$fillable` — which group
        // a session belongs to decides who is offered it, so it is never a
        // mass-assignable field. Same spelling `ScheduleClassSession` uses.
        $session->forceFill(['cohort_id' => $cohortId])->save();
    }

    /**
     * The one guarded increment of `seats_taken` in the tree. Three entry points
     * reach it and none of them may spell it a second time.
     */
    private function claimCapacity(ClassSession $session): void
    {
        $claimed = ClassSession::query()
            ->whereKey($session->getKey())
            ->whereColumn('seats_taken', '<', 'seats_total')
            ->increment('seats_taken');

        if ($claimed === 0) {
            throw new DomainException('اكتملت مقاعد هذه الحصة.');
        }
    }

    /**
     * Give a claimed seat back when the row could not be written. Guarded at
     * zero, so a compensation that runs twice cannot walk the count negative.
     */
    private function releaseCapacity(ClassSession $session): void
    {
        ClassSession::query()
            ->whereKey($session->getKey())
            ->where('seats_taken', '>', 0)
            ->decrement('seats_taken');
    }
}
