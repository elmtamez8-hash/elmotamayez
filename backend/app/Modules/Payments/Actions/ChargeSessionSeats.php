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
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\BalanceAnnouncer;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Payments\Support\ExamMode;
use App\Shared\Actions\Action;
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

        if ($seatHolders === [] || $billableSeats === 0) {
            $this->stamp($session);

            return [];
        }

        $mismatch = count($seatHolders) !== $billableSeats;

        if ($mismatch) {
            Log::warning('006: the frozen seat count disagrees with the bookings', [
                'class_session_id' => $session->getKey(),
                'billable_seats' => $billableSeats,
                'seat_holders' => count($seatHolders),
            ]);
        }

        // Once for the session, not once per seat: it is a fact about the
        // workspace and the moment, and thirty students share both.
        $inExamWindow = $this->examMode->isOpen((int) $session->workspace_id);

        $entries = [];

        foreach ($seatHolders as $student) {
            $entry = $this->chargeOne($session, $course, $student, $billableSeats, count($seatHolders), $mismatch, $inExamWindow);

            if ($entry !== null) {
                $entries[] = $entry;
            }
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
        Course $course,
        User $student,
        int $billableSeats,
        int $seatHolders,
        bool $mismatch,
        bool $inExamWindow,
    ): ?CreditTransaction {
        $balance = $this->accounts->balanceFor($student, $course);

        // Before the write. Afterwards it would be the state the balance is in
        // now, and every transition would be invisible.
        $wasBlocked = $this->announcer->isBlocked($balance, $inExamWindow);

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

        // BalanceUpdated, the threshold crossing and any withholding flip — all
        // four in one place, so the three Actions that write to the ledger cannot
        // drift into announcing three different subsets.
        $this->announcer->announce($balance, $wasBlocked, -1, $inExamWindow);

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
