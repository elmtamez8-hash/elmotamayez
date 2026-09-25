<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditTransaction;
use App\Shared\Contracts\SessionSeatCharges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
            expiresAt: $this->expiryOfLotsDrawnBy($charge),
        ));

        return $entry !== null;
    }

    /**
     * The expiry the returned credit goes back with: that of the batch the
     * charge took it from (see `CreditLedger::openLot()`).
     *
     * A charge drawn across several lots returns with the LATEST of their
     * expiries, and with none at all if any of them never expires — the credit
     * given back is one credit, and the student is given the benefit of the
     * doubt about which of the batches it was. A charge that drew from no lot
     * (it was delivered at zero, into debt) has nothing to inherit, and returns
     * undated like a bonus.
     */
    private function expiryOfLotsDrawnBy(CreditTransaction $charge): ?CarbonImmutable
    {
        $expiries = DB::table('credit_allocations')
            ->join('credit_lots', 'credit_lots.credit_transaction_id', '=', 'credit_allocations.lot_transaction_id')
            ->where('credit_allocations.consumed_transaction_id', $charge->getKey())
            ->pluck('credit_lots.expires_at');

        if ($expiries->isEmpty() || $expiries->contains(null)) {
            return null;
        }

        return CarbonImmutable::parse((string) $expiries->max());
    }
}
