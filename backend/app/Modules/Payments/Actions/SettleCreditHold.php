<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\CreditHold;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * ٠٣٥ — ختمُ الحجز: إمّا خُصِمَ وإمّا عادَ إلى صاحبِه.
 *
 * ⚠️ THE CLAIM AND THE DECREMENT ARE ONE TRANSACTION. A worker killed between
 * them leaves `held_credits` permanently wrong — and nothing in the whole
 * product reads `credit_holds`, which is why the fourth nightly invariant had
 * to be written for it (`ReconcileCreditBalancesJob::holdsAgainstBalances()`).
 *
 * ⚠️ AND THE CLAIM IS `WHERE settled_at IS NULL`, WHICH IS BOTH THE CHECK AND
 * THE CLAIM. The settler is a listener AND a periodic sweep working the same
 * seats BY DESIGN, so a read-then-write releases twice — and releasing is not a
 * ledger entry, so no unique index absorbs the second one. The student would
 * simply gain a credit they never bought, and no invariant in the product would
 * see it. `lockForUpdate()` is banned: a no-op on SQLite.
 *
 * ⚠️ THE BULK FORM IS TWO STATEMENTS WHATEVER THE SEAT COUNT, and the counter is
 * written FROM A SUBQUERY rather than decremented. Absolute, so a double
 * settlement is a no-op; relative, and cancelling a thirty-seat session twice
 * takes sixty credits out of thirty students' balances.
 */
class SettleCreditHold extends Action
{
    /**
     * Settle every live hold on this session, or only those of the students named.
     *
     * @param  list<int>  $studentUserIds  empty means every live hold on the session
     * @return int how many holds this call actually settled
     */
    public function handle(int $classSessionId, string $outcome, array $studentUserIds = []): int
    {
        return DB::transaction(function () use ($classSessionId, $outcome, $studentUserIds): int {
            $claim = DB::table('credit_holds')
                ->where('class_session_id', $classSessionId)
                ->whereNull('settled_at');

            if ($studentUserIds !== []) {
                $claim->whereIn('student_user_id', $studentUserIds);
            }

            // Read the balances BEFORE the claim: afterwards the rows no longer
            // match the predicate that finds them, and a second query would have
            // to re-derive the set from a column it has just overwritten.
            $balanceIds = (clone $claim)->distinct()->pluck('credit_balance_id')->all();

            $settled = $claim->update([
                'settled_at' => now(),
                'outcome' => $outcome,
                'updated_at' => now(),
            ]);

            if ($settled === 0) {
                return 0;
            }

            /*
            | ⚠️ ABSOLUTE, FROM A SUBQUERY — never `decrement()`. Two settlers on
            | the same seats is the design, not an edge case: the charge listener
            | and `SweepStaleCreditHoldsJob` both settle, and the second one's
            | claim matching zero rows is what makes it harmless. A relative
            | decrement would still be applied by whichever call did match, and
            | the counter would drift below zero with nothing to notice.
            |
            | One statement for every balance touched, whatever the seat count.
            */
            DB::table('credit_balances')
                ->whereIn('id', $balanceIds)
                ->update([
                    'held_credits' => DB::raw(
                        '(SELECT COALESCE(SUM(h.credits), 0) FROM credit_holds h'
                        .' WHERE h.credit_balance_id = credit_balances.id AND h.settled_at IS NULL)'
                    ),
                    'updated_at' => now(),
                ]);

            return $settled;
        });
    }

    /** Settle one named hold — the single-seat door. */
    public function one(CreditHold $hold, string $outcome): bool
    {
        return $this->handle(
            (int) $hold->class_session_id,
            $outcome,
            [(int) $hold->student_user_id],
        ) > 0;
    }
}
