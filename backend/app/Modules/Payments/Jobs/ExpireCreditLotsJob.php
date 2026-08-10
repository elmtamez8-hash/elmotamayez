<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\CreditExpired;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Support\CreditLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Lots whose day has passed, written off — and SWITCHED OFF at launch (Q-5).
 *
 * ⚠️ IT FINDS NOTHING TODAY, BY DESIGN AND NOT BY OVERSIGHT.
 * `credit_packages.validity_days` defaults to null, `CreditLot::expires_at`
 * follows it, and this job selects on a dated lot — so a platform that never sets
 * a validity has nothing here to act on. It is built now for the reason
 * {@see CreditExpired} was: switching expiry on afterwards would be a migration
 * over credits people bought on the understanding that they were permanent, and
 * the consumption order it depends on (soonest-expiring first) has been live
 * since day one so that turning it on cannot rewrite which lot paid for which
 * past session.
 *
 * ⚠️ THE WRITE-OFF GOES THROUGH THE LEDGER, never straight at the balance. The
 * remaining counter, the entry and the audit trail are one movement — an UPDATE
 * on `credit_balances` here would leave the balance disagreeing with the sum of
 * its own entries, which is exactly what {@see ReconcileCreditBalancesJob} would
 * then report every night.
 *
 * Safe to run twice: the lot is emptied by a conditional UPDATE that claims the
 * exact remainder it read, and the entry carries the lot id as its idempotency
 * key. A second pass over an expired lot claims nothing and writes nothing.
 */
class ExpireCreditLotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CreditLedger $ledger): void
    {
        CreditLot::query()
            ->withoutWorkspaceScope()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('credits_remaining', '>', 0)
            ->chunkById(200, function (iterable $lots) use ($ledger): void {
                foreach ($lots as $lot) {
                    $this->expire($lot, $ledger);
                }
            });
    }

    private function expire(CreditLot $lot, CreditLedger $ledger): void
    {
        $remainder = $lot->credits_remaining;

        // The claim, in the shape a seat is claimed: one conditional UPDATE that
        // both checks and takes. `count() then update()` is the race itself — a
        // consumption landing between the two would be paid for out of a lot this
        // job has already decided to write off, and the student would lose a
        // credit they had just spent.
        $claimed = CreditLot::query()
            ->withoutWorkspaceScope()
            ->whereKey($lot->getKey())
            ->where('credits_remaining', $remainder)
            ->update(['credits_remaining' => 0]);

        if ($claimed === 0) {
            return;
        }

        $balance = CreditBalance::query()->withoutWorkspaceScope()->find($lot->credit_balance_id);

        if ($balance === null) {
            return;
        }

        $entry = $ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Expire,
            credits: -$remainder,
            sourceType: 'credit_lot',
            // The idempotency key: one write-off per lot, whatever replays.
            sourceId: (int) $lot->getKey(),
            reason: 'انتهت صلاحية دفعة أرصدة.',
            // The lot above was emptied by the claim. Letting the drawer run
            // would take the same credits again, out of lots that have not
            // expired — the student would lose twice what ran out.
            drawsFromLots: false,
        ));

        if ($entry !== null) {
            // After the transaction, for the reason nothing in the ledger
            // dispatches: an event fired inside one announces a movement that may
            // still roll back.
            DB::afterCommit(fn () => CreditExpired::dispatch($entry));
        }
    }
}
