<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\SessionUnlock;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ٠٣٥ — الفعلُ الوحيدُ الذي يُنفِقُ حصّةً بقرارِ الطالبِ نفسِه.
 *
 * ⚠️ THE CONDITIONS LIVE HERE AND NOT IN THE CONTROLLER. This is the one entry
 * point the seeder, the panel and the HTTP surface all share, and a rule that
 * exists only on the HTTP path is a rule with a door beside it.
 *
 * ⛔ THE LEDGER SOURCE IS `session_unlock`, NOT `class_session`. The charge at
 * close already occupies `('class_session', <this session id>)` — measured at
 * `ChargeSessionSeats.php:263` — so a second entry under the same key collides
 * on the idempotency index, `insertOrIgnore` writes zero rows, `post()` returns
 * null, and a caller reading null as «already done» opens the content WITH NO
 * CHARGE AND NO RECORD. The whole feature would look like it worked.
 *
 * ⛔ AND A NULL FROM THE LEDGER IS RECONCILED AGAINST THE UNLOCK ROW, never read
 * as success. A row already present means a genuine duplicate (409); a row
 * absent means the write was swallowed for some other reason, and that THROWS.
 * `insertOrIgnore` cannot tell a duplicate from a failure on its own, which is
 * exactly why `CreditLedger::writeEntry()` reads back.
 *
 * ⛔ `workspace_id` IS ASSIGNED FROM THE SESSION. The writer is a student's own
 * request and a student is a member of no workspace, so `BelongsToWorkspace`'s
 * auto-fill writes nothing at all.
 *
 * ⛔ AND THE FLOOR IS ON, WITH THE HELD CREDITS SUBTRACTED. The default is off
 * for a correct reason in its own place — a session that was delivered is owed
 * whether or not the student can pay — but this is a VOLUNTARY purchase. Forget
 * it and the balance goes below zero, the student reads as defaulted, and every
 * future booking and every lesson of a course they paid for is shut, by the
 * press of the button FR-013 forbids disabling. `zeroFloor` stays at its own
 * default of true: the credit limit is room to defer a debt that AROSE, not an
 * overdraft for an optional purchase.
 */
class UnlockSessionContent extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly CreditAccounts $accounts,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  string  $reason  one of `SessionUnlock::REASON_*`
     * @param  int  $credits  1 for a consent, 0 for an automatic opening
     */
    public function handle(
        User $student,
        ClassSession $session,
        string $reason = SessionUnlock::REASON_CONSENT,
        int $credits = 1,
    ): SessionUnlock {
        $course = $session->course_id === null
            ? null
            : Course::query()->withoutWorkspaceScope()->find($session->course_id);

        if ($course === null) {
            throw new DomainException('لا يمكن فتح محتوى حصّة بلا كورس.');
        }

        $balance = $this->accounts->balanceFor($student, $course);

        /*
        | ⚠️ ONE OUTER TRANSACTION AROUND BOTH WRITES, and the failure is
        | asymmetric in both directions without it: a crash after the entry is a
        | credit spent on content still locked with no record of why, and a crash
        | after the row is a free unlock.
        */
        return DB::transaction(function () use ($student, $session, $balance, $reason, $credits): SessionUnlock {
            $entry = $credits > 0
                ? $this->ledger->post(new CreditMovement(
                    balance: $balance,
                    type: CreditTransactionType::Consume,
                    credits: -$credits,
                    // ⛔ INDEPENDENT. See the class docblock.
                    sourceType: 'session_unlock',
                    sourceId: (int) $session->getKey(),
                    performedBy: (int) $student->getKey(),
                    meta: ['reason' => $reason],
                    enforceFloor: true,
                    subtractHeld: true,
                ))
                : null;

            try {
                $unlock = SessionUnlock::query()->create([
                    // Explicit: the writer has no workspace context at all.
                    'workspace_id' => (int) $session->workspace_id,
                    'student_user_id' => (int) $student->getKey(),
                    'class_session_id' => (int) $session->getKey(),
                    'credits_charged' => $credits,
                    'reason' => $reason,
                    'credit_transaction_id' => $entry?->getKey(),
                    'consented_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $collision) {
                // ⚠️ THE UNIQUE KEY IS THE GUARD, never a read beforehand: two
                // taps arrive together and a read-then-write lets both through.
                throw new DomainException('محتوى هذه الحصّة مفتوحٌ لك بالفعل.', 409, $collision);
            }

            if ($credits > 0 && $entry === null) {
                /*
                | The ledger swallowed the entry and the unlock row is NEW — so
                | this is not a duplicate press, it is a write that failed for
                | some other reason. Throwing rolls the row back with it; reading
                | it as success would open the content for nothing.
                */
                throw new RuntimeException('تعذّر تسجيل خصم فتح محتوى الحصّة.');
            }

            /*
            | ⚠️ THE AUDIT LINE, AND WITHOUT IT FR-012 IS SATISFIED ON PAPER AND
            | INVISIBLE ON THE ONE SCREEN AN AUDITOR OPENS. `activity_log` is what
            | the billing audit reads, and `BillingAuditSubjects` has to name this
            | subject type or the row is filtered straight back out.
            */
            $this->logActivity('billing.session.unlocked', $unlock, [
                'class_session_id' => (int) $session->getKey(),
                'credits' => $credits,
                'reason' => $reason,
            ]);

            return $unlock;
        });
    }
}
