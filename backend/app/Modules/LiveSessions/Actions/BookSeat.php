<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\LiveSessions\Support\LeadTime;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\SessionCreditHolds;
use Carbon\CarbonImmutable;
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
        private readonly SessionCreditHolds $holds,
        private readonly LeadTime $lead,
    ) {}

    /**
     * ⚠️ ALL THREE ENTRIES ASK `bookingScopeRefusal()` FIRST — the course the
     * session belongs to and, when it is filed under a group, that group (owner
     * decision 2026-09-25). Asked here rather than in a controller because this
     * is the one door the «احجز» button, a teacher's grant and the subscription
     * auto-booker (`ClaimSubscriptionSeats`) all walk through.
     */
    public function handle(ClassSession $session, User $student): SessionBooking
    {
        $this->assertBookable($session);

        $undone = $this->undoLateCancellation($session, $student);

        if ($undone !== null) {
            return $undone;
        }

        /*
        | ⚠️ THE LEAD TIME GUARDS AN OPEN 1:1 SLOT, AT THIS DOOR ONLY. A private
        | request is refused «too soon» (`RequestPrivateSession`), and an open 1:1
        | slot generated from the same availability was not — so the hour a
        | request could not take, the «احجز» button took one minute before it
        | started, with nobody able to prepare for it. «Open» is `cohort_id IS
        | NULL`: a 1:1 session already filed under one student was timed by the
        | teacher for that student, and the automation that seats members
        | (`ClaimSubscriptionSeats::claimOneAsMember()`, which also walks through
        | here) must not be refused an hour the teacher chose. A teacher's grant
        | and the revival of a released seat are the other two entries and are
        | not asked. A group lesson happens whoever books it, so it keeps «not
        | started».
        */
        if ($session->type === ClassSessionType::Individual && $session->cohort_id === null) {
            $tooSoon = $this->lead->refusalFor(CarbonImmutable::instance($session->starts_at));

            if ($tooSoon !== null) {
                throw new DomainException($tooSoon);
            }
        }

        // ⚠️ `openingRefusal`, not `refusalReason`: booking is one of the two
        // doors FR-041 names, so 008's unlock condition is asked here too.
        return $this->claim(
            $session,
            $student,
            $this->eligibility->bookingScopeRefusal($session, $student)
                ?? $this->eligibility->openingRefusal($session, $student),
        );
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
     * ⚠️ `$subscriptionCovered` IS NOT THAT FLAG, AND THE DIFFERENCE IS WHAT IT
     * SELECTS. The rule above is about the DOOR — which refusal is asked — and
     * that stays a named method. This one says who already PAID for the hour,
     * which is a fact about the seat rather than a choice of question, and it
     * cannot be a fourth entry point because it cuts across this one: the same
     * `claimGrantedSeat()` serves a subscriber (their month bought it) and a
     * teacher granting a private request (the student pays in credits like
     * anybody else). Passed by NAME at every call site, so it reads there.
     *
     * ⚠️ AND IT ASKS `refusalReason()`, NOT `openingRefusal()`. The unlock gate
     * (008 · FR-036) governs the next lesson on a course PATH; a private hour is
     * what a student asks for precisely because they are behind on it, so asking
     * it here would refuse exactly the student the feature exists for — and
     * would refuse them at the teacher's press, after the teacher already
     * decided. The money and enrolment conditions are asked, because those the
     * teacher cannot waive.
     */
    public function claimGrantedSeat(
        ClassSession $session,
        User $student,
        bool $subscriptionCovered = false,
    ): SessionBooking {
        $this->assertBookable($session);

        return $this->claim(
            $session,
            $student,
            $this->eligibility->bookingScopeRefusal($session, $student)
                ?? $this->eligibility->refusalReason($session, $student),
            $subscriptionCovered,
        );
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
     * ⚠️ AND ONLY `Released` IS REVIVED BY THIS ENTRY. A student's own
     * cancellation stays cancelled for the AUTOMATION (FR-044) — a booking they
     * undid must not come back in the night. The student's own «احجز» may take
     * it back ({@see self::claim()}), which is the same UPDATE over a wider set of
     * statuses, because a person pressing a button is not the night. The
     * conditional `where status = released` is what makes this true under a race
     * as well: a seat re-booked by hand a millisecond earlier affects zero rows
     * here and the claimed capacity goes straight back.
     */
    public function reviveReleasedSeat(
        ClassSession $session,
        User $student,
        bool $subscriptionCovered = false,
    ): SessionBooking {
        $this->assertBookable($session);

        $refusal = $this->eligibility->bookingScopeRefusal($session, $student)
            ?? $this->eligibility->refusalReason($session, $student);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return DB::transaction(function () use ($session, $student, $subscriptionCovered): SessionBooking {
            $this->claimCapacity($session);

            $booking = $this->reviveRow($session, $student, [BookingStatus::Released]);

            if ($booking === null) {
                $this->releaseCapacity($session);

                throw new DomainException('لم يعد هذا المقعد قابلاً للاسترجاع.');
            }

            // A revived seat freezes a credit exactly as a fresh one does — and
            // the row it reuses is why `credit_holds` carries `hold_seq`: the
            // first hold was settled when the seat was taken away.
            $this->freezeCredit($session, $student, $subscriptionCovered);

            // A 1:1 slot whose group was taken off when this seat went
            // (`ClassSession::reopenEmptyIndividualSlot()`) is filed again.
            $this->fileUnderTheStudentsOwnGroup($session, $student);

            $session->refresh();

            return $booking;
        });
    }

    /**
     * Puts the student's existing row back to `booked`, in ONE statement, when
     * its status is one of `$from` — and hands the row back, or null.
     *
     * ⚠️ THE ONE SPELLING OF A REVIVAL, FOR BOTH ENTRIES THAT REVIVE. The
     * automation revives `Released` only; the student's own button revives their
     * own in-time cancellation as well. Two UPDATEs written apart drift at the
     * first column somebody adds to one of them.
     *
     * ⚠️ CALLED AFTER `claimCapacity()`, NEVER BEFORE IT, and the caller gives the
     * capacity back on null: the seat count is the overbooking guard, and a row
     * flipped to `booked` without it would put a student in a full room. The one
     * exception is `CancelledLate` ({@see self::undoLateCancellation()}), whose
     * seat never left `seats_taken`.
     *
     * @param  list<BookingStatus>  $from
     */
    private function reviveRow(ClassSession $session, User $student, array $from): ?SessionBooking
    {
        $revived = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->whereIn('status', array_map(static fn (BookingStatus $status): string => $status->value, $from))
            ->update([
                'status' => BookingStatus::Booked->value,
                'is_billable' => true,
                'booked_at' => now(),
                'cancelled_at' => null,
                'cancellation_reason' => null,
                /*
                | ⚠️ WHAT BELONGED TO THE SEAT BEFORE IT WENT GOES WITH IT. The
                | reminder job claims `reminded_at IS NULL`, so a row reminded
                | before it was cancelled would never be reminded of the lesson
                | it was just booked back into. And an excuse (`ExcuseBooking`)
                | exempts THAT absence from the charge — a student who books the
                | hour again has not been excused from the hour they now hold.
                */
                'reminded_at' => null,
                'excused_at' => null,
                'excused_by_user_id' => null,
                'updated_at' => now(),
            ]);

        if ($revived === 0) {
            return null;
        }

        return SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->first();
    }

    /**
     * «تراجع عن الإلغاء» — a late cancellation taken back (owner decision
     * 2026-09-26). Null when the student holds no late-cancelled row here.
     *
     * ⛔ NO CAPACITY CLAIM AND NO CREDIT HOLD, AND THAT IS THE WHOLE POINT. A late
     * cancellation never gave its seat back (`seats_taken` still counts it), its
     * credit hold was never released, and it is still charged at delivery — so
     * the chair, the frozen credit and the charge are all still the student's.
     * Claiming either again would sell one chair twice. The row simply goes
     * back to `booked`.
     *
     * ⚠️ ONE CONDITIONAL UPDATE (`WHERE status = cancelled_late`, through
     * `reviveRow()`), so a double tap moves the row once and the second press
     * finds it booked and is refused like any second booking — nothing is
     * counted twice. Only before the lesson starts: `assertBookable()` has
     * already refused a session that started or stopped taking bookings. No
     * eligibility or lead-time question is asked: nothing new is being bought.
     */
    private function undoLateCancellation(ClassSession $session, User $student): ?SessionBooking
    {
        return $this->reviveRow($session, $student, [BookingStatus::CancelledLate]);
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

    private function claim(
        ClassSession $session,
        User $student,
        ?string $refusal,
        bool $subscriptionCovered = false,
    ): SessionBooking {
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return DB::transaction(function () use ($session, $student, $subscriptionCovered): SessionBooking {
            $this->claimCapacity($session);

            /*
            | ⛔ A ROW THAT IS ALREADY THERE IS NOT «A SEAT YOU HOLD». The row is
            | the record of what happened to the seat, so it stays when the seat
            | goes — and the unique index on (session, student) then refused every
            | INSERT after it with «لديك مقعد محجوز في هذه الحصة بالفعل», about a
            | seat the student had given up in time or the system had taken back.
            | No screen had a way out. Both of those are revived here, by the
            | same conditional UPDATE the automation's revival uses, AFTER the
            | capacity claim.
            |
            | ⚠️ `CancelledLate` IS NOT IN THE LIST, ON PURPOSE. Its seat never
            | went back to the pool (`seats_taken` still counts it), its credit is
            | still frozen, and it is still charged at delivery — so reviving it
            | through here would claim a second seat and a second credit for one
            | chair. The student's own door takes it back BEFORE this point, with
            | no capacity and no hold ({@see self::undoLateCancellation()}); the
            | other two entries never do.
            */
            $booking = $this->reviveRow($session, $student, [
                BookingStatus::CancelledInWindow,
                BookingStatus::Released,
            ]);

            if ($booking === null) {
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
                    // The row is there and is not one we may revive. Give the
                    // capacity we just claimed back, or a double-tap on the
                    // button would eat a seat nobody occupies.
                    $this->releaseCapacity($session);

                    throw new DomainException($this->heldRowRefusal($session, $student));
                }
            }

            // ⛔ AFTER THE BOOKING ROW AND BEFORE THE COMMIT. Placed above the
            // insert, two workers racing for the last seat both compute
            // `hold_seq = 0` and the second violates the hold index — which the
            // catcher above does not cover, because it is written for the SEAT
            // index and answers «لديك مقعد محجوز» about a seat nobody holds.
            $this->freezeCredit($session, $student, $subscriptionCovered);

            $this->fileUnderTheStudentsOwnGroup($session, $student);

            $session->refresh();

            return $booking;
        });
    }

    /**
     * The sentence for a row that is there and could not be revived — read,
     * because «you already hold it» is false about a late cancellation.
     */
    private function heldRowRefusal(ClassSession $session, User $student): string
    {
        // `value()` goes through the model, so the cast hands back the enum.
        $status = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->value('status');

        return $status === BookingStatus::CancelledLate
            ? 'ألغيت هذا الحجز بعد انتهاء مهلة الإلغاء، فبقي مقعدك محسوباً عليك ولا يمكن حجزه من جديد.'
            : 'لديك مقعد محجوز في هذه الحصة بالفعل.';
    }

    /**
     * ٠٣٥ · T058 — freeze one credit for this seat, or give the seat back.
     *
     * ⛔ INSIDE THE BOOKING'S OWN TRANSACTION, WHICH IS THE WHOLE REASON THE HOLD
     * IS A CONTRACT AND NOT AN EVENT. A listener runs after the commit, by which
     * time the seat is sold — so a refusal has to throw from in here, where the
     * rollback hands the seat back in the same statement that took it.
     *
     * ⛔ AND A SUBSCRIPTION SEAT NEVER REACHES IT. The subscriber's month has
     * already paid for the hour and they hold no credit balance to freeze, so a
     * hold here would refuse them a seat they own (٠٢٧ · FR-041). The branch that
     * decides is at the CALL SITE — `ClaimSubscriptionSeats` — and not a guess
     * made here: `claimGrantedSeat()` also serves a teacher granting a private
     * request, where the student pays in credits like anybody else, so «granted»
     * and «covered» are two different facts about one door.
     *
     * ⚠️ AND A REFUSAL IS A SENTENCE, never a bare «no». The contract answers
     * what is available and when the soonest frozen credit comes back, because a
     * refusal a student can do nothing with is the shape FR-013 forbids.
     */
    private function freezeCredit(ClassSession $session, User $student, bool $subscriptionCovered): void
    {
        if ($subscriptionCovered || $session->course_id === null) {
            return;
        }

        $result = $this->holds->place(
            $student,
            (int) $session->getKey(),
            (int) $session->course_id,
            (int) $session->workspace_id,
        );

        if ($result->granted) {
            return;
        }

        $back = $result->firstReleaseAt === null
            ? null
            : (date_create_immutable($result->firstReleaseAt) ?: null)?->format('Y-m-d H:i');

        throw new DomainException(
            $back === null
                ? 'رصيدك لا يكفي لحجز هذه الحصة. اشترِ رصيداً من صفحة الأرصدة.'
                : "رصيدك محجوزٌ لحصصٍ أخرى. أوّل ما يعود منه بعد انتهاء حصة {$back}، أو اشترِ رصيداً من صفحة الأرصدة.",
        );
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
        //
        // ⚠️ AND `cohort_from_booking` SAYS THE SEAT PUT IT THERE. When this seat
        // goes, the slot is nobody's again and the group comes off
        // (`ClassSession::reopenEmptyIndividualSlot()`) — which must never
        // happen to a 1:1 session a teacher SCHEDULED for one student (a
        // granted private request is filed under that student at creation).
        $session->forceFill(['cohort_id' => $cohortId, 'cohort_from_booking' => true])->save();
    }

    /**
     * The one guarded increment of `seats_taken` in the tree. Three entry points
     * reach it and none of them may spell it a second time.
     */
    private function claimCapacity(ClassSession $session): void
    {
        /*
        | ⚠️ UNSCOPED: the row is already chosen by its key, and the booker may be
        | a student whose stamped `last_workspace_id` names ANOTHER teacher — under
        | the scope this matches zero rows and a free seat reads «اكتملت».
        */
        $claimed = ClassSession::query()
            ->withoutWorkspaceScope()
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
            ->withoutWorkspaceScope()
            ->whereKey($session->getKey())
            ->where('seats_taken', '>', 0)
            ->decrement('seats_taken');
    }
}
