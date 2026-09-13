<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditTransaction;
use App\Shared\Contracts\SessionSeatCharges;

/**
 * ٠٣٥ — «قبِلتُ عذرَه بعدَ أن أُغلقَتِ الحصّة»: القيدُ العكسيّ.
 *
 * @see SessionSeatCharges for why this is a contract and not a call
 */
class EloquentSessionSeatCharges implements SessionSeatCharges
{
    public function __construct(private readonly CreditLedger $ledger) {}

    public function reverse(User $student, int $classSessionId, string $reason): bool
    {
        /*
        | The original charge, found by the key the charge itself used. A zero
        | entry is not a charge — the seat was covered by a subscription or was
        | exempt already — so there is nothing to give back and this answers
        | false rather than writing a zero reversal nobody can interpret.
        */
        $charge = CreditTransaction::query()
            ->withoutWorkspaceScope()
            ->where('type', CreditTransactionType::Consume)
            ->where('source_type', 'class_session')
            ->where('source_id', $classSessionId)
            ->where('credits', '<', 0)
            // ⚠️ THE RELATION BUILDER IS TYPED FROM THE RELATION, so the bypass is
            // named on the MODEL's own query rather than on the closure's
            // argument, which PHPStan reads as a bare `Builder<Model>`.
            ->whereIn('credit_balance_id', CreditBalance::query()
                ->withoutWorkspaceScope()
                ->where('student_user_id', $student->getKey())
                ->select('id'))
            ->first();

        if ($charge === null) {
            return false;
        }

        $balance = $charge->balance;

        if ($balance === null) {
            return false;
        }

        /*
        | ⛔ AN INDEPENDENT SOURCE, AND THIS IS THE WHOLE OF WHY THE REVERSAL
        | WORKS. The ledger's idempotency key is (balance, type, source_type,
        | source_id) — so a reversal filed under `class_session` with the same id
        | collides with the charge it is undoing, `insertOrIgnore` writes zero
        | rows, the read-back returns the ORIGINAL, and the Action reports
        | success while the student never gets their credit back. To anybody
        | watching, the feature works.
        |
        | `credits` is POSITIVE and the type is `Adjustment`, not `Consume`: a
        | consume moves `consumed_credits`, and an excuse accepted late must
        | leave the student's consumed count telling the truth about the lesson
        | they did not take.
        |
        | The floor is off. A refund is never refused for want of room.
        */
        $entry = $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Adjustment,
            credits: 1,
            sourceType: 'session_charge_reversal',
            sourceId: $classSessionId,
            reason: $reason,
            meta: ['reverses_transaction_uuid' => $charge->uuid],
            enforceFloor: false,
        ));

        return $entry !== null;
    }
}
