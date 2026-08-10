<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\CreditConsumed;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\BalanceAnnouncer;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Payments\Support\ExamMode;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

/**
 * One credit per frozen seat, for a session the teacher actually delivered.
 *
 * Reached only from `SessionDelivered`, which 005 fires solely when the teacher
 * joined, stayed long enough and the session ended normally. FR-024, FR-025أ and
 * FR-025ج are therefore conditions on FIRING THE EVENT, upstream — they are not
 * re-checked here, and a second copy of them would be a second thing to keep in
 * step.
 *
 * The seat count is the event's `billableSeats`: a fact about the moment the
 * cancellation window shut. The bookings are read for IDENTITY only, because a
 * ledger entry has to name whose seat it was. That split — and the refusal to
 * quietly prefer either number when they disagree — is the same rule the
 * teacher's side applies to the same event.
 *
 * ⚠️ Which is as close as this file may come to saying so. Naming the class over
 * there, even in a `{@see}`, becomes a real `use` statement the moment Pint's
 * fully_qualified_strict_types fixer runs, and ContextIsolationTest fails the
 * build over an import across this boundary — correctly. What a student pays and
 * what a teacher earns share one event and nothing else.
 *
 * ⚠️ ATTENDANCE IS NOT CONSULTED, ANYWHERE (FR-025د). The seat is what was sold:
 * the recording, the files and the homework reach the student who did not show
 * up, so `Absent` and `Excused` charge exactly like `Present`. `Excused` is a
 * pastoral mark, never a financial exemption (FR-025ب).
 */
class ChargeSessionSeats extends Action
{
    public function __construct(
        private readonly CreditAccounts $accounts,
        private readonly CreditLedger $ledger,
        private readonly BalanceAnnouncer $announcer,
        private readonly ExamMode $examMode,
    ) {}

    /** @return list<CreditTransaction> */
    public function handle(ClassSession $session, int $billableSeats): array
    {
        $course = $session->course;

        if ($course === null) {
            // A pre-Q-7 session in a multi-course workspace: the backfill left it
            // null rather than guessing, and a balance is held per (student,
            // course), so there is nothing to charge against.
            //
            // Stamped anyway. `charged_at` means "the charge ran to completion",
            // and leaving it null here would have the sweep pick this session up
            // every fifteen minutes for the life of the product.
            $this->stamp($session);

            return [];
        }

        $seatHolders = $this->seatHolders($session);

        if ($seatHolders === []) {
            // Nobody held a seat, so there is nothing to charge and nothing to
            // repair. Stamped, or the sweep picks this session up every fifteen
            // minutes for the life of the product.
            $this->stamp($session);

            return [];
        }

        /*
        | ⚠️ ZERO WITH SEAT HOLDERS PRESENT IS A MISSING FACT, NOT A FACT.
        |
        | `billable_seats` is nullable — written once, at the cancellation
        | deadline — and ChargeUnbilledDeliveriesJob coerces null to zero on the
        | way in. Zero is an honest reading of a SEAT COUNT; it is not an honest
        | reading of `charged_at`, which means "the charge ran to completion".
        |
        | This branch used to exit here with the stamp written, which took the
        | session out of the sweep — the only path that could ever have repaired
        | it — over a room of students nobody debited. And silently: the mismatch
        | warning below never ran, because this returned first. The nightly
        | reconciliation then reported that session every night, permanently,
        | with nothing anywhere able to clear it.
        |
        | So the seat holders win. They are the fact; the frozen count is an
        | optimisation of it, and a missing optimisation does not erase the fact.
        */
        if ($billableSeats === 0) {
            Log::warning('006: charging a delivered session whose frozen seat count is missing', [
                'class_session_id' => $session->getKey(),
                'seat_holders' => count($seatHolders),
            ]);

            $billableSeats = count($seatHolders);
        }

        $mismatch = count($seatHolders) !== $billableSeats;

        if ($mismatch) {
            Log::warning('006: the frozen seat count disagrees with the bookings', [
                'class_session_id' => $session->getKey(),
                'billable_seats' => $billableSeats,
                'seat_holders' => count($seatHolders),
            ]);
        }

        /*
        | ⚠️ EVERY SHARED FACT IS READ ONCE FOR THE SESSION, NEVER ONCE PER SEAT.
        |
        | The exam window was already hoisted here; the billing mode, the
        | zero-balance behaviour, the workspace row itself and the terms consent
        | were not, and each of them was resolved inside the loop — three times
        | per seat for the workspace alone, once through the lazy relation, again
        | through `refresh()`, and again through the blocked check. Thirty
        | students in one class share all four facts, exactly as they share the
        | exam window.
        |
        | The before-state is therefore taken for the WHOLE ROOM in one bulk read,
        | and the flips are announced for the whole room afterwards. Both methods
        | already existed on the announcer — they were written for the exam-mode
        | window, which moves every balance in a workspace at once. This is the
        | same shape, one room smaller.
        */
        $inExamWindow = $this->examMode->isOpen((int) $session->workspace_id);

        $balances = new EloquentCollection(array_map(
            fn (User $student): CreditBalance => $this->accounts->balanceFor($student, $course),
            $seatHolders,
        ));

        /*
        | One Workspace instance, shared by every balance in the room.
        |
        | The ledger reads the alert thresholds off `$balance->workspace` while
        | ranking a crossing, and that relation is lazy — so without this line the
        | same row is SELECTed once per seat, at the exact moment the loop is
        | already at its most expensive. Set from the course, which was loaded
        | before any of this began.
        */
        $workspace = $course->workspace;

        if ($workspace !== null) {
            $balances->each(fn (CreditBalance $balance) => $balance->setRelation('workspace', $workspace));
        }

        $wasBlocked = $this->announcer->standingsFor($balances);

        $entries = [];

        foreach ($balances as $balance) {
            $entry = $this->chargeOne($session, $balance, $billableSeats, count($seatHolders), $mismatch);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        // Re-read rather than re-stamped: `stamp()` writes `is_withheld` onto the
        // instance it is given, so re-stamping the same collection would
        // overwrite the very answers being compared against and every transition
        // would read as "no change".
        if ($entries !== []) {
            $this->announcer->announceStandingChanges(
                CreditBalance::query()->withoutWorkspaceScope()->whereIn('id', $balances->modelKeys())->get(),
                $wasBlocked,
            );
        }

        // AFTER the whole loop, never per seat. A throw partway through leaves
        // the stamp unwritten, the sweep picks the session up again, and the
        // seats already charged are refused by their idempotency key rather than
        // charged twice. Stamping per seat would mark a half-charged session
        // done, which is the silent loss this column exists to prevent.
        $this->stamp($session);

        return $entries;
    }

    /**
     * Who held a seat when it froze.
     *
     * `CancelledLate` counts: cancelling after the deadline does not release the
     * seat, so it was taught to. The same filter builds 005's register and 014's
     * teaching units — three readers of one rule, which is what keeps the
     * student's charge, the teacher's pay and the register in step.
     *
     * @return list<User>
     */
    private function seatHolders(ClassSession $session): array
    {
        $ids = $session->bookings()
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
            ->pluck('student_user_id')
            ->unique()
            ->all();

        return array_values(User::query()->whereIn('id', $ids)->get()->all());
    }

    private function chargeOne(
        ClassSession $session,
        CreditBalance $balance,
        int $billableSeats,
        int $seatHolders,
        bool $mismatch,
    ): ?CreditTransaction {

        $entry = $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Consume,
            credits: -1,
            sourceType: 'class_session',
            // The idempotency key. The unique index on
            // (balance, type, source_type, source_id) is what makes a replayed
            // event, a retried job and an operator running the sweep twice land
            // on one entry — not a count-then-insert, which is the race itself.
            sourceId: (int) $session->getKey(),
            // Written at INSERT, because the ledger is append-only:
            // CreditTransaction::booted() throws on `updating`, so a discrepancy
            // noticed after the row exists has nowhere to go. A log line alone
            // does not survive rotation, and "worth a human look" has to.
            meta: [
                'billable_seats' => $billableSeats,
                'seat_holders' => $seatHolders,
                'seat_count_mismatch' => $mismatch,
            ],
            // Explicit, though it is also the DTO's default. The floor guards
            // BOOKING; this is the recording of a debt already incurred.
            // Refusing it would leave the platform owing the teacher — 014
            // earned them their fee from this same event — with no claim
            // recorded against anyone (research › R17). Exam mode makes that
            // systematic, since it forces the floor to zero.
            enforceFloor: false,
        ));

        if ($entry === null) {
            // Already charged. The duplicate is the mechanism working rather
            // than a failure, so nothing is said and nothing is dispatched.
            return null;
        }

        // Dispatched HERE, not in the ledger: the ledger moves the balance inside
        // a transaction, and an event fired inside one announces a movement that
        // may still roll back.
        CreditConsumed::dispatch($entry, (int) $session->getKey());

        // The movement's own half only. The withholding flip is announced for the
        // whole room after the loop, because its four inputs are facts about the
        // workspace and the moment rather than about this student.
        $this->announcer->announceMovement($balance, -1);

        return $entry;
    }

    private function stamp(ClassSession $session): void
    {
        if ($session->charged_at !== null) {
            return;
        }

        $session->forceFill(['charged_at' => now()])->save();
    }
}
