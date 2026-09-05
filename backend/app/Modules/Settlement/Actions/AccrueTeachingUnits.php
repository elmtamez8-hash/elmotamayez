<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Settlement\Enums\SettlementBasis;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\PackageCompletion;
use App\Modules\Settlement\Support\RateResolver;
use App\Modules\Settlement\Support\SettlementSettings;
use App\Shared\Actions\Action;
use Illuminate\Database\QueryException;

/**
 * One unit per frozen seat, for a session the teacher actually delivered.
 *
 * Reached only from `SessionDelivered`, which 005 fires solely when the teacher
 * joined, stayed long enough and the session ended normally. That condition is
 * NOT re-checked here: it lives in one file upstream on purpose, and a second
 * copy of it would be a second thing to keep in step.
 *
 * The seat count is the event's `billableSeats` — a fact about the moment the
 * cancellation window shut. The bookings are read for identity only, because a
 * row has to name whose seat it was; if the two disagree, that is a discrepancy
 * worth a human look rather than a number to quietly prefer (FR-007أ).
 */
class AccrueTeachingUnits extends Action
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly PackageCompletion $package,
        private readonly SettlementSettings $settings,
    ) {}

    /**
     * @param  list<int>  $subscriptionSeats  seat holders a duration package already paid for
     * @return list<TeachingUnit>
     */
    public function handle(ClassSession $session, int $billableSeats, array $subscriptionSeats = []): array
    {
        $seatHolderIds = $this->seatHolderIds($session);

        if ($seatHolderIds === [] || $billableSeats === 0) {
            return $this->compensateEmptySession($session, $billableSeats);
        }

        $rate = $this->rates->resolve(
            (int) $session->teacher_profile_id,
            $session->type,
            $session->starts_at,
            $session->subject_id === null ? null : (int) $session->subject_id,
            // Passed, not left to default. Omitting it left the fifth argument
            // null, which made `orWhere('grade_level', null)` the only branch
            // that could ever match — so every grade-specific rate a teacher had
            // approved was invisible at settlement and the general rate was paid
            // instead. Spec 006 resolves the SAME rate for the purchase price,
            // so the two sides would disagree from the first grade-scoped rate.
            $session->grade_level,
        );

        /*
        | ⚠️ A SUBSCRIBER IS PAID FOR BY WHO TURNED UP, NOT BY WHO WAS BOOKED
        | (product decision 2026-09-05). Automatic booking puts every member of
        | the group into every lesson, so pricing a subscriber's seat by the
        | BOOKING pays the teacher for twelve people who were never in the room:
        | the seat count stopped being evidence of anything the moment nobody had
        | to press «احجز» for it to exist.
        |
        | ⚠️ AND IT IS ASKED FOR SUBSCRIBERS ONLY. Everywhere else in this product
        | attendance has NO financial effect — the billable count is frozen at the
        | cancellation deadline and never recomputed, and
        | `AttendanceHasNoFinancialEffectTest` fails the build over a breach. A
        | student who bought a LESSON bought the seat and pays for it whether they
        | come or not; a subscriber bought a MONTH, and the month has already paid.
        */
        $attended = $subscriptionSeats === [] ? [] : $this->attendedStudentIds($session);

        $missing = $this->package->missingReason($session);
        $mismatch = count($seatHolderIds) !== $billableSeats;
        $units = [];

        foreach ($seatHolderIds as $studentUserId) {
            /*
            | ⚠️ THE DECISION IS PER SEAT HOLDER, NOT PER SESSION (027 · FR-048).
            | One rate is resolved for the whole lesson, but a room mixes people
            | who bought a lesson with people who bought a month, and the second
            | kind must not make the payout grow with the timetable. A count on
            | the event could not do this — it would not say WHICH rows.
            |
            | The list arrives ON the delivery event. Settlement never asks the
            | money module about a subscription: `ContextIsolationTest` fails the
            | build on the first import in that direction, and this Action holds
            | no idea what a subscription is beyond «a seat already paid for».
            */
            $bySubscription = in_array($studentUserId, $subscriptionSeats, true);
            $earns = ! $bySubscription || in_array($studentUserId, $attended, true);

            $unit = $this->accrueOne(
                $session,
                $studentUserId,
                $billableSeats,
                $earns ? $rate?->getKey() : null,
                $earns ? $rate?->amount_minor : 0,
                $missing,
                $mismatch,
                $bySubscription,
            );

            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * Who was actually in the room (027 · product decision 2026-09-05).
     *
     * `Present` and `Late` only. `Absent` is nobody taught, and `Excused` is an
     * absence the teacher decided not to hold against the STUDENT — a mercy
     * towards them, not an hour anybody spent in the lesson. The host's own row
     * is excluded: `CloseClassSession` judges delivery from it, and counting it
     * here would pay the teacher for attending themselves.
     *
     * ⚠️ AND THE SOURCE IS AN ALLOWLIST, NOT A DENYLIST. Only a mark meaning «was
     * in the room» earns: the heartbeat's own, and a teacher's manual correction
     * of it. That shape is deliberate and load-bearing for what is coming — a
     * student who missed the lesson and later watched the recording, or took the
     * handout, is to be marked ATTENDED for their own record and must NOT be
     * counted among the sessions the platform pays the teacher for (product
     * decision 2026-09-05: the platform sometimes opens a lesson for a new
     * student, or gives one as a reward). Written as a denylist, the day that
     * mark is introduced it would silently start earning; written this way, a new
     * source earns nothing until somebody adds it here on purpose.
     *
     * ⚠️ AND NO ENUM VALUE IS ADDED HERE FOR IT. A case with readers and no writer
     * is a requirement everybody believes is implemented — this tree has paid for
     * that once already (`ClassSessionStatus::Interrupted`). The catch-up mark
     * arrives with the thing that writes it.
     *
     * @return list<int>
     */
    private function attendedStudentIds(ClassSession $session): array
    {
        $attended = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->excludingHost($session)
            ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::Late->value])
            ->whereIn('source', [AttendanceSource::Automatic->value, AttendanceSource::Manual->value])
            ->pluck('student_user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return array_values($attended);
    }

    /**
     * Who held a seat when it froze.
     *
     * `CancelledLate` counts: cancelling after the deadline does not release the
     * seat, so the teacher taught to it. This is the same filter 005 uses to
     * build the register, which is what keeps the two in step.
     *
     * @return list<int>
     */
    private function seatHolderIds(ClassSession $session): array
    {
        return array_values(array_map(
            static fn ($id): int => (int) $id,
            $session->bookings()
                ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
                ->pluck('student_user_id')
                ->all(),
        ));
    }

    private function accrueOne(
        ClassSession $session,
        int $studentUserId,
        int $billableSeats,
        ?int $rateId,
        ?int $amountMinor,
        ?string $missing,
        bool $mismatch,
        bool $bySubscription = false,
    ): ?TeachingUnit {
        // A missing rate must not swallow the work: the hour was taught, and a
        // unit worth nothing that says so is recoverable, while no row at all is
        // a gap nobody notices until the teacher counts.
        // A subscriber's seat is deliberately unpriced here, so it is not the
        // «no rate approved» case and must not be flagged for review as one.
        $unpriced = $rateId === null && ! $bySubscription;

        $attributes = [
            'workspace_id' => (int) $session->workspace_id,
            'student_user_id' => $studentUserId,
            'teacher_profile_id' => (int) $session->teacher_profile_id,
            'class_session_id' => (int) $session->getKey(),
            'session_type' => $session->type,
            'settlement_rate_id' => $rateId,
            'amount_minor' => $amountMinor ?? 0,
            'currency' => $this->settings->currency(),
            'frozen_seats' => $billableSeats,
            'basis' => $bySubscription ? SettlementBasis::SubscriptionSeat : SettlementBasis::FrozenSeat,
            'status' => $missing === null ? TeachingUnitStatus::Accrued : TeachingUnitStatus::PendingPackage,
            'pending_reason' => $missing,
            'recording_fault' => $missing === null && $this->package->isRecordingFault($session),
            'needs_review' => $unpriced || $mismatch,
            'delivered_at' => $session->delivered_at ?? now(),
            'accrued_at' => $missing === null ? now() : null,
            'reversal_of_id' => TeachingUnit::NOT_A_REVERSAL,
        ];

        try {
            return TeachingUnit::query()->create($attributes);
        } catch (QueryException $e) {
            // The unique index on (session, student, reversal_of_id) is what
            // makes this idempotent — not a count-then-insert, which is the
            // definition of the race. A retried job, a replayed event and an
            // operator running the sweep twice all land here.
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A session nobody booked.
     *
     * Earns nothing by default (FR-008هـ): zero seats is a suspicious state —
     * a mis-scheduled slot, a test, an attempt to route around the platform — and
     * paying for it silently is how that becomes a habit. The switch exists
     * because a teacher who showed up and delivered the package may still
     * deserve something, and either way the session is flagged (FR-008ح).
     *
     * @return list<TeachingUnit>
     */
    private function compensateEmptySession(ClassSession $session, int $billableSeats): array
    {
        if (! $this->settings->zeroAttendanceCompensationEnabled()) {
            return [];
        }

        $rate = $this->rates->resolve(
            (int) $session->teacher_profile_id,
            $session->type,
            $session->starts_at,
            $session->subject_id === null ? null : (int) $session->subject_id,
            // Passed, not left to default. Omitting it left the fifth argument
            // null, which made `orWhere('grade_level', null)` the only branch
            // that could ever match — so every grade-specific rate a teacher had
            // approved was invisible at settlement and the general rate was paid
            // instead. Spec 006 resolves the SAME rate for the purchase price,
            // so the two sides would disagree from the first grade-scoped rate.
            $session->grade_level,
        );

        if ($rate === null) {
            return [];
        }

        $amount = intdiv($rate->amount_minor * $this->settings->zeroAttendanceCompensationPercent(), 100);

        if ($amount === 0) {
            return [];
        }

        $attributes = [
            'workspace_id' => (int) $session->workspace_id,
            // No seat means no student. The column is nullable in intent but not
            // in schema, so the teacher's own user id stands in as the subject of
            // a row that is about them, not about a learner.
            'student_user_id' => (int) $session->teacherProfile?->user_id,
            'teacher_profile_id' => (int) $session->teacher_profile_id,
            'class_session_id' => (int) $session->getKey(),
            'session_type' => $session->type,
            'settlement_rate_id' => $rate->getKey(),
            'amount_minor' => $amount,
            'currency' => $this->settings->currency(),
            'frozen_seats' => $billableSeats,
            'basis' => SettlementBasis::ZeroAttendanceCompensation,
            'status' => TeachingUnitStatus::Accrued,
            'needs_review' => true,
            'delivered_at' => $session->delivered_at ?? now(),
            'accrued_at' => now(),
            'reversal_of_id' => TeachingUnit::NOT_A_REVERSAL,
        ];

        /*
         * ⚠️ THE SAME GUARD ITS SIBLING HAS, AND ITS ABSENCE HERE WAS LOAD-BEARING.
         *
         * `accrueOne()` wraps its `create()` because the unique index on
         * (session, student, reversal_of_id) is what makes accrual idempotent —
         * and this row lands on that same index, with the TEACHER standing in as
         * the student. So a redelivered event, a retried job or an operator
         * running the sweep twice threw a `QueryException` out of the listener
         * rather than returning quietly.
         *
         * That mattered far past this method: until this listener was queued, the
         * throw propagated into `CloseClassSession` and took the guardian report,
         * the counters and the recording ingest with it. Both halves are fixed;
         * either alone would have left the other's failure mode intact.
         */
        try {
            $unit = TeachingUnit::query()->create($attributes);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return [];
            }

            throw $e;
        }

        return [$unit];
    }

    private function isDuplicate(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
